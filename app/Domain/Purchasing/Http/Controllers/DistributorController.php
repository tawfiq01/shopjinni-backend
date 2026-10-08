<?php

namespace App\Domain\Purchasing\Http\Controllers;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\PaymentMethod;
use App\Domain\Accounting\Services\AccountingService;
use App\Domain\Purchasing\Http\Resources\DistributorResource;
use App\Domain\Purchasing\Models\Distributor;
use App\Domain\Purchasing\Models\PurchaseInvoice;
use App\Domain\Purchasing\Models\PurchasePayment;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DistributorController extends Controller
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function index(Request $request)
    {
        $query = Distributor::query();

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('company_name', 'like', $term)
                    ->orWhere('mobile', 'like', $term);
            });
        }

        return DistributorResource::collection($query->orderBy('name')->get());
    }

    public function show(Distributor $distributor)
    {
        return new DistributorResource($distributor);
    }

    public function ledger(Distributor $distributor)
    {
        return response()->json([
            'distributor' => new DistributorResource($distributor),
            'lines' => $this->accounting->ledgerForParty($distributor),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:30'],
            'alt_mobile' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'email' => ['nullable', 'email', 'max:255'],
            'opening_balance' => ['nullable', 'numeric'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $distributor = Distributor::create($data);

        $openingBalance = round((float) ($data['opening_balance'] ?? 0), 2);
        if ($openingBalance !== 0.0) {
            $this->postOpeningBalance($distributor, $openingBalance, $request);
        }

        return new DistributorResource($distributor);
    }

    public function update(Request $request, Distributor $distributor)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'mobile' => ['sometimes', 'string', 'max:30'],
            'alt_mobile' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'email' => ['nullable', 'email', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // opening_balance is intentionally not editable after creation — it
        // was already posted to the ledger; correct it with a manual journal
        // entry instead so the audit trail stays intact.
        $distributor->update($data);

        return new DistributorResource($distributor);
    }

    public function destroy(Distributor $distributor)
    {
        $hasLedgerHistory = JournalLine::query()
            ->where('party_type', 'distributor')
            ->where('party_id', $distributor->id)
            ->exists();

        if ($hasLedgerHistory) {
            return response()->json([
                'message' => 'This distributor has ledger/purchase history and cannot be deleted. Deactivate it instead.',
            ], 409);
        }

        $distributor->delete();

        return response()->json(status: 204);
    }

    /**
     * Record a payment against a distributor's outstanding due. Unlike the
     * Accounts section's manual Journal Entry form, this always tags the
     * payable line with the distributor as `party`, so it's immediately
     * visible to currentBalance() — and therefore the Dashboard and Dues
     * Report, which both read that same method. A manual journal entry
     * posted without a party looks like it "clears" the due (cash drops)
     * but never actually reduces the figure those screens show.
     */
    public function pay(Request $request, Distributor $distributor)
    {
        $companyId = $request->user()->company_id;
        $currentDue = $distributor->currentBalance();

        if ($currentDue <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['This distributor has no outstanding due to pay.'],
            ]);
        }

        $data = $request->validate([
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('company_id', $companyId)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.$currentDue],
            'paid_at' => ['nullable', 'date'],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $paymentMethod = PaymentMethod::findOrFail($data['payment_method_id']);
        $amount = round($data['amount'], 2);
        $paidAt = $data['paid_at'] ?? now()->toDateString();

        $entry = DB::transaction(function () use ($distributor, $paymentMethod, $data, $amount, $paidAt, $request) {
            // Allocate oldest-first across open invoices so each invoice's
            // own paid/due amount (what the Purchase Report reads) stays
            // correct too, not just the ledger balance. If the payment
            // exceeds the sum of open invoice dues — e.g. some of the
            // balance came from an opening balance or a manual entry with
            // no invoice behind it — the remainder is simply left
            // unallocated at the invoice level; the ledger entry below
            // still covers the full amount, which is what currentBalance()
            // actually reads.
            $remaining = $amount;
            $openInvoices = PurchaseInvoice::where('distributor_id', $distributor->id)
                ->where('due_amount', '>', 0)
                ->orderBy('purchase_date')
                ->orderBy('id')
                ->get();

            foreach ($openInvoices as $invoice) {
                if ($remaining <= 0) {
                    break;
                }

                $applied = round(min($remaining, (float) $invoice->due_amount), 2);

                PurchasePayment::create([
                    'purchase_invoice_id' => $invoice->id,
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => $applied,
                    'paid_at' => $paidAt,
                    'reference_no' => $data['reference_no'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $request->user()->id,
                ]);

                $invoice->update([
                    'paid_amount' => round((float) $invoice->paid_amount + $applied, 2),
                    'due_amount' => round((float) $invoice->due_amount - $applied, 2),
                ]);

                $remaining = round($remaining - $applied, 2);
            }

            $payableAccount = ChartOfAccount::where('code', '2000')->firstOrFail();

            return $this->accounting->postEntry(
                lines: [
                    ['account_id' => $payableAccount->id, 'debit' => $amount, 'party' => $distributor],
                    ['account_id' => $paymentMethod->chart_of_account_id, 'credit' => $amount],
                ],
                narration: "Payment to {$distributor->name}",
                entryDate: $paidAt,
                reference: $distributor,
                createdBy: $request->user()->id,
            );
        });

        return response()->json([
            'distributor' => new DistributorResource($distributor->fresh()),
            'journal_entry_id' => $entry->id,
        ]);
    }

    private function postOpeningBalance(Distributor $distributor, float $amount, Request $request): void
    {
        $payable = ChartOfAccount::where('code', '2000')->firstOrFail(); // Accounts Payable
        $equity = ChartOfAccount::where('code', '3100')->firstOrFail(); // Opening Balance Equity

        $lines = $amount > 0
            ? [
                ['account_id' => $equity->id, 'debit' => abs($amount)],
                ['account_id' => $payable->id, 'credit' => abs($amount), 'party' => $distributor],
            ]
            : [
                ['account_id' => $payable->id, 'debit' => abs($amount), 'party' => $distributor],
                ['account_id' => $equity->id, 'credit' => abs($amount)],
            ];

        $this->accounting->postEntry(
            lines: $lines,
            narration: "Opening balance — {$distributor->name}",
            createdBy: $request->user()?->id,
            reference: $distributor,
        );
    }
}

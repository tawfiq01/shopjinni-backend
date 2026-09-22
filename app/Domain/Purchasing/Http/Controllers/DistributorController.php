<?php

namespace App\Domain\Purchasing\Http\Controllers;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Services\AccountingService;
use App\Domain\Purchasing\Http\Resources\DistributorResource;
use App\Domain\Purchasing\Models\Distributor;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

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

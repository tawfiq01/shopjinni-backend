<?php

namespace App\Domain\Customers\Http\Controllers;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Services\AccountingService;
use App\Domain\Customers\Http\Resources\CustomerResource;
use App\Domain\Customers\Models\Customer;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function index(Request $request)
    {
        $query = Customer::query();

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)->orWhere('mobile', 'like', $term);
            });
        }

        return CustomerResource::collection($query->orderBy('name')->get());
    }

    public function show(Customer $customer)
    {
        return new CustomerResource($customer);
    }

    public function ledger(Customer $customer)
    {
        return response()->json([
            'customer' => new CustomerResource($customer),
            'lines' => $this->accounting->ledgerForParty($customer),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'email' => ['nullable', 'email', 'max:255'],
            'opening_balance' => ['nullable', 'numeric'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $customer = Customer::create($data);

        $openingBalance = round((float) ($data['opening_balance'] ?? 0), 2);
        if ($openingBalance !== 0.0) {
            $this->postOpeningBalance($customer, $openingBalance, $request);
        }

        return new CustomerResource($customer);
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'mobile' => ['sometimes', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'email' => ['nullable', 'email', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // opening_balance is intentionally not editable after creation — see
        // DistributorController for the same rule and rationale.
        $customer->update($data);

        return new CustomerResource($customer);
    }

    public function destroy(Customer $customer)
    {
        $hasLedgerHistory = JournalLine::query()
            ->where('party_type', 'customer')
            ->where('party_id', $customer->id)
            ->exists();

        if ($hasLedgerHistory) {
            return response()->json([
                'message' => 'This customer has ledger/sales history and cannot be deleted. Deactivate it instead.',
            ], 409);
        }

        $customer->delete();

        return response()->json(status: 204);
    }

    private function postOpeningBalance(Customer $customer, float $amount, Request $request): void
    {
        $receivable = ChartOfAccount::where('code', '1100')->firstOrFail(); // Accounts Receivable
        $equity = ChartOfAccount::where('code', '3100')->firstOrFail(); // Opening Balance Equity

        $lines = $amount > 0
            ? [
                ['account_id' => $receivable->id, 'debit' => abs($amount), 'party' => $customer],
                ['account_id' => $equity->id, 'credit' => abs($amount)],
            ]
            : [
                ['account_id' => $equity->id, 'debit' => abs($amount)],
                ['account_id' => $receivable->id, 'credit' => abs($amount), 'party' => $customer],
            ];

        $this->accounting->postEntry(
            lines: $lines,
            narration: "Opening balance — {$customer->name}",
            createdBy: $request->user()?->id,
            reference: $customer,
        );
    }
}

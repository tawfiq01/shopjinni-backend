<?php

namespace App\Domain\Expenses\Http\Controllers;

use App\Domain\Accounting\Services\AccountingService;
use App\Domain\Branches\Models\Branch;
use App\Domain\Expenses\Http\Resources\ExpenseResource;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\ExpenseCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function index(Request $request)
    {
        $query = Expense::with(['category', 'paymentAccount'])->orderByDesc('date')->orderByDesc('id');

        if ($request->filled('category_id')) {
            $query->where('expense_category_id', $request->integer('category_id'));
        }
        if ($request->filled('from')) {
            $query->where('date', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->where('date', '<=', $request->date('to'));
        }

        return ExpenseResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request)
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'expense_category_id' => ['required', Rule::exists('expense_categories', 'id')->where('company_id', $companyId)],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)],
            'description' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('company_id', $companyId)],
        ]);

        $branchId = $data['branch_id'] ?? $request->user()->branch_id ?? Branch::where('is_main', true)->value('id');
        $category = ExpenseCategory::findOrFail($data['expense_category_id']);

        $expense = DB::transaction(function () use ($data, $branchId, $category, $request) {
            $expense = Expense::create([
                'branch_id' => $branchId,
                'expense_category_id' => $category->id,
                'date' => $data['date'],
                'amount' => $data['amount'],
                'payment_account_id' => $data['payment_account_id'],
                'description' => $data['description'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $this->accounting->postEntry(
                lines: [
                    ['account_id' => $category->chart_of_account_id, 'debit' => $data['amount']],
                    ['account_id' => $data['payment_account_id'], 'credit' => $data['amount']],
                ],
                narration: "Expense: {$category->name}".($data['description'] ?? '' ? " — {$data['description']}" : ''),
                branchId: $branchId,
                entryDate: $data['date'],
                reference: $expense,
                createdBy: $request->user()->id,
            );

            return $expense;
        });

        return new ExpenseResource($expense);
    }
}

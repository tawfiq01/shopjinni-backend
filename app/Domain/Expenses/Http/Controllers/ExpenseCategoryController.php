<?php

namespace App\Domain\Expenses\Http\Controllers;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Expenses\Http\Resources\ExpenseCategoryResource;
use App\Domain\Expenses\Models\ExpenseCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseCategoryController extends Controller
{
    public function index()
    {
        return ExpenseCategoryResource::collection(ExpenseCategory::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:expense_categories,name'],
        ]);

        $category = DB::transaction(function () use ($data) {
            $account = ChartOfAccount::create([
                'code' => $this->nextExpenseAccountCode(),
                'name' => $data['name'],
                'type' => 'expense',
                'parent_id' => ChartOfAccount::where('code', '5100')->value('id'),
                'is_system' => false,
                'is_active' => true,
            ]);

            return ExpenseCategory::create([
                'name' => $data['name'],
                'chart_of_account_id' => $account->id,
            ]);
        });

        return new ExpenseCategoryResource($category);
    }

    private function nextExpenseAccountCode(): string
    {
        $parentId = ChartOfAccount::where('code', '5100')->value('id');
        $count = ChartOfAccount::where('parent_id', $parentId)->count();

        return '51'.str_pad((string) ($count + 1), 2, '0', STR_PAD_LEFT);
    }
}

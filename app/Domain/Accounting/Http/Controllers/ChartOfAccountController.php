<?php

namespace App\Domain\Accounting\Http\Controllers;

use App\Domain\Accounting\Http\Resources\ChartOfAccountResource;
use App\Domain\Accounting\Models\ChartOfAccount;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChartOfAccountController extends Controller
{
    public function index()
    {
        return ChartOfAccountResource::collection(
            ChartOfAccount::orderBy('code')->get()
        );
    }

    public function store(Request $request)
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('chart_of_accounts', 'code')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:asset,liability,equity,income,expense'],
            'parent_id' => ['nullable', Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)],
        ]);

        $account = ChartOfAccount::create([...$data, 'is_system' => false]);

        return new ChartOfAccountResource($account);
    }
}

<?php

namespace App\Domain\Accounting\Http\Controllers;

use App\Domain\Accounting\Http\Resources\ChartOfAccountResource;
use App\Domain\Accounting\Models\ChartOfAccount;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

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
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:chart_of_accounts,code'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:asset,liability,equity,income,expense'],
            'parent_id' => ['nullable', 'exists:chart_of_accounts,id'],
        ]);

        $account = ChartOfAccount::create([...$data, 'is_system' => false]);

        return new ChartOfAccountResource($account);
    }
}

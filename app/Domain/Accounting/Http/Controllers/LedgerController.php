<?php

namespace App\Domain\Accounting\Http\Controllers;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Services\AccountingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function forAccount(Request $request, ChartOfAccount $account)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return response()->json([
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ],
            'lines' => $this->accounting->ledgerForAccount($account, $data['from'] ?? null, $data['to'] ?? null),
        ]);
    }
}

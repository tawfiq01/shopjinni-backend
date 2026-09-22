<?php

namespace App\Domain\Reports\Http\Controllers;

use App\Domain\Accounting\Models\PaymentMethod;
use App\Http\Controllers\Controller;

class CashPositionController extends Controller
{
    public function index()
    {
        $rows = PaymentMethod::with('account')->where('is_active', true)->get()
            ->map(fn (PaymentMethod $method) => [
                'method' => $method->name,
                'account_code' => $method->account->code,
                'balance' => $method->account->balance(),
            ]);

        return response()->json([
            'data' => $rows,
            'total' => round((float) $rows->sum('balance'), 2),
        ]);
    }
}

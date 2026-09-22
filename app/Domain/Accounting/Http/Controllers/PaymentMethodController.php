<?php

namespace App\Domain\Accounting\Http\Controllers;

use App\Domain\Accounting\Models\PaymentMethod;
use App\Http\Controllers\Controller;

class PaymentMethodController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => PaymentMethod::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}

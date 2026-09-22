<?php

namespace App\Domain\Reports\Http\Controllers;

use App\Domain\Customers\Models\Customer;
use App\Domain\Purchasing\Models\Distributor;
use App\Http\Controllers\Controller;

class DueReportController extends Controller
{
    public function index()
    {
        $customers = Customer::where('is_active', true)->get()
            ->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->name, 'mobile' => $c->mobile, 'due' => $c->currentBalance()])
            ->filter(fn ($row) => abs($row['due']) > 0.005)
            ->sortByDesc('due')
            ->values();

        $distributors = Distributor::where('is_active', true)->get()
            ->map(fn (Distributor $d) => ['id' => $d->id, 'name' => $d->name, 'mobile' => $d->mobile, 'due' => $d->currentBalance()])
            ->filter(fn ($row) => abs($row['due']) > 0.005)
            ->sortByDesc('due')
            ->values();

        return response()->json([
            'customers' => $customers,
            'distributors' => $distributors,
            'total_customer_due' => round((float) $customers->sum('due'), 2),
            'total_distributor_due' => round((float) $distributors->sum('due'), 2),
        ]);
    }
}

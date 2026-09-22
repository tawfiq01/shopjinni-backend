<?php

namespace App\Domain\Reports\Http\Controllers;

use App\Domain\Sales\Models\SalesInvoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SalesReportController extends Controller
{
    public function summary(Request $request)
    {
        $from = ($request->date('from') ?? now()->subDays(29))->startOfDay();
        $to = ($request->date('to') ?? now())->endOfDay();
        $canViewCost = $request->user()->can('reports.view-cost');

        // Bounds are full timestamps (not just 'Y-m-d' strings) so this
        // compares correctly regardless of whether the DB stores DATE
        // columns with or without a time component (SQLite adds one).
        $invoices = SalesInvoice::whereBetween('sale_date', [$from, $to])
            ->with(['items.sku.variant.model.brand', 'items.sku.color', 'salesperson', 'customer'])
            ->get();

        $byDay = $invoices->groupBy(fn ($invoice) => $invoice->sale_date->toDateString())
            ->map(fn ($group, $date) => [
                'date' => $date,
                'total' => round((float) $group->sum('total'), 2),
                'count' => $group->count(),
            ])
            ->sortBy('date')
            ->values();

        $allItems = $invoices->flatMap(fn ($invoice) => $invoice->items);

        $byModel = $allItems->groupBy(fn ($item) => $item->sku->variant->model->id)
            ->map(function ($items) {
                $model = $items->first()->sku->variant->model;

                return [
                    'model_name' => "{$model->brand->name} {$model->name}",
                    'quantity' => $items->sum('quantity'),
                    'total' => round((float) $items->sum('line_total'), 2),
                ];
            })
            ->sortByDesc('total')
            ->values();

        $byColor = $allItems->groupBy(fn ($item) => $item->sku->color->id)
            ->map(function ($items) {
                return [
                    'color_name' => $items->first()->sku->color->name,
                    'quantity' => $items->sum('quantity'),
                    'total' => round((float) $items->sum('line_total'), 2),
                ];
            })
            ->sortByDesc('total')
            ->values();

        $bySalesperson = $invoices->groupBy(fn ($invoice) => $invoice->salesperson_id ?? 0)
            ->map(fn ($group) => [
                'name' => $group->first()->salesperson->name ?? 'Unassigned',
                'total' => round((float) $group->sum('total'), 2),
                'count' => $group->count(),
            ])
            ->sortByDesc('total')
            ->values();

        $byCustomer = $invoices->groupBy(fn ($invoice) => $invoice->customer_id ?? 0)
            ->map(fn ($group) => [
                'name' => $group->first()->customer->name ?? 'Walk-in',
                'total' => round((float) $group->sum('total'), 2),
                'count' => $group->count(),
            ])
            ->sortByDesc('total')
            ->values();

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'invoice_count' => $invoices->count(),
            'total_sales' => round((float) $invoices->sum('total'), 2),
            'total_discount' => round((float) $invoices->sum('discount'), 2),
            'total_due' => round((float) $invoices->sum('due_amount'), 2),
            ...($canViewCost ? ['total_profit' => round((float) $invoices->sum('profit'), 2)] : []),
            'by_day' => $byDay,
            'by_model' => $byModel,
            'by_color' => $byColor,
            'by_salesperson' => $bySalesperson,
            'by_customer' => $byCustomer,
        ]);
    }
}

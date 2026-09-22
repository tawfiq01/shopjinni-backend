<?php

namespace App\Domain\Reports\Http\Controllers;

use App\Domain\Purchasing\Models\PurchaseInvoice;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PurchaseReportController extends Controller
{
    public function summary(Request $request)
    {
        $from = ($request->date('from') ?? now()->subDays(29))->startOfDay();
        $to = ($request->date('to') ?? now())->endOfDay();

        // Bounds are full timestamps, not 'Y-m-d' strings — see
        // SalesReportController for why (SQLite date-column quirk).
        $invoices = PurchaseInvoice::with(['distributor', 'items.sku.variant.model.brand', 'items.sku.color'])
            ->whereBetween('purchase_date', [$from, $to])
            ->get();

        $byDistributor = $invoices->groupBy('distributor_id')
            ->map(fn ($group) => [
                'distributor_id' => $group->first()->distributor_id,
                'distributor_name' => $group->first()->distributor->name,
                'total' => round((float) $group->sum('total'), 2),
                'count' => $group->count(),
            ])
            ->sortByDesc('total')
            ->values();

        $byProduct = $invoices->flatMap(fn ($invoice) => $invoice->items)
            ->groupBy('product_variant_color_id')
            ->map(function ($items) {
                $sku = $items->first()->sku;
                $model = $sku->variant->model;
                $quantity = $items->sum('quantity');
                $total = (float) $items->sum('line_total');

                return [
                    'sku_id' => $sku->id,
                    'display_name' => sprintf(
                        '%s %s %s (%s)',
                        $model->brand->name,
                        $model->name,
                        $sku->variant->label(),
                        $sku->color->name,
                    ),
                    'quantity' => $quantity,
                    'total' => round($total, 2),
                    'avg_unit_cost' => $quantity > 0 ? round($total / $quantity, 2) : 0.0,
                ];
            })
            ->sortByDesc('total')
            ->values();

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'invoice_count' => $invoices->count(),
            'total_purchases' => round((float) $invoices->sum('total'), 2),
            'total_due' => round((float) $invoices->sum('due_amount'), 2),
            'by_distributor' => $byDistributor,
            'by_product' => $byProduct,
        ]);
    }

    public function priceHistory(Request $request)
    {
        $request->validate(['sku_id' => ['required', 'integer', 'exists:product_variant_colors,id']]);

        // Ordered oldest-to-newest batch creation (id order), the same
        // ordering FIFO consumption relies on — see InventoryService.
        $batches = PurchaseItem::where('product_variant_color_id', $request->integer('sku_id'))
            ->with('purchaseInvoice.distributor')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PurchaseItem $item) => [
                'purchase_item_id' => $item->id,
                'date' => $item->purchaseInvoice->purchase_date->toDateString(),
                'invoice_number' => $item->purchaseInvoice->invoice_number,
                'distributor_name' => $item->purchaseInvoice->distributor->name,
                'quantity' => $item->quantity,
                'unit_cost' => (float) $item->unit_cost,
                'remaining_quantity' => $item->remaining_quantity,
            ]);

        return response()->json(['data' => $batches]);
    }
}

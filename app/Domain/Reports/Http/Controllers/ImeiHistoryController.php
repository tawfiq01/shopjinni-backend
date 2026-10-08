<?php

namespace App\Domain\Reports\Http\Controllers;

use App\Domain\Inventory\Models\ImeiUnit;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Sales\Models\SaleItem;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ImeiHistoryController extends Controller
{
    public function show(Request $request)
    {
        $data = $request->validate(['imei' => ['required', 'string']]);
        $canViewCost = $request->user()->can('reports.view-cost');

        $unit = ImeiUnit::where('imei1', $data['imei'])
            ->orWhere('imei2', $data['imei'])
            ->with(['sku.variant.model.brand', 'sku.color', 'purchaseItem.purchaseInvoice.distributor'])
            ->first();

        if (! $unit) {
            return response()->json(['message' => 'No unit found with that IMEI.'], 404);
        }

        $saleItems = SaleItem::where('imei_unit_id', $unit->id)
            ->with('salesInvoice.customer')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $saleIndex = 0;

        $movements = StockMovement::where('imei_unit_id', $unit->id)
            ->with('branch')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (StockMovement $movement) use ($canViewCost, $saleItems, &$saleIndex) {
                $saleDetails = [];
                if ($movement->movement_type === StockMovement::TYPE_SALE && isset($saleItems[$saleIndex])) {
                    $saleItem = $saleItems[$saleIndex++];
                    $invoice = $saleItem->salesInvoice;
                    if ($invoice) {
                        $saleDetails = [
                            'sale_date' => $invoice->sale_date->toDateString(),
                            'sale_invoice_number' => $invoice->invoice_number,
                            'sale_customer' => $invoice->customer?->name,
                            'sale_unit_price' => (float) $saleItem->unit_price,
                            'sale_discount' => (float) $saleItem->discount,
                            'sale_total' => (float) $saleItem->line_total,
                        ];
                    }
                }

                return [
                    'date' => $movement->created_at->toDateTimeString(),
                    'type' => $movement->movement_type,
                    'branch' => $movement->branch->name,
                    'quantity_change' => $movement->quantity_change,
                    ...($canViewCost ? ['unit_cost' => $movement->unit_cost] : []),
                    ...$saleDetails,
                ];
            });

        $model = $unit->sku->variant->model;

        return response()->json([
            'unit' => [
                'id' => $unit->id,
                'imei1' => $unit->imei1,
                'imei2' => $unit->imei2,
                'status' => $unit->status,
                'is_demo' => $unit->is_demo,
                'display_name' => sprintf(
                    '%s %s %s (%s)',
                    $model->brand->name,
                    $model->name,
                    $unit->sku->variant->label(),
                    $unit->sku->color->name,
                ),
                'purchased_at' => $unit->purchased_at?->toDateString(),
                'sold_at' => $unit->sold_at?->toDateString(),
                'distributor' => $unit->purchaseItem->purchaseInvoice->distributor->name,
                ...($canViewCost ? ['purchase_cost' => $unit->purchaseItem->unit_cost] : []),
            ],
            'movements' => $movements,
        ]);
    }
}

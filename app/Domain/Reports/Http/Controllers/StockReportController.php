<?php

namespace App\Domain\Reports\Http\Controllers;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariantColor;
use App\Domain\Inventory\Models\ImeiUnit;
use App\Domain\Inventory\Models\InventoryStock;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockReportController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->integer('branch_id') ?: ($request->user()->branch_id ?? Branch::where('is_main', true)->value('id'));

        $query = ProductVariantColor::query()
            ->with(['variant.model.brand', 'variant.model.productType', 'color'])
            ->where('is_active', true);

        if ($request->filled('brand_id')) {
            $query->whereHas('variant.model', fn ($m) => $m->where('brand_id', $request->integer('brand_id')));
        }
        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($term) {
                $q->where('sku', 'like', $term)
                    ->orWhereHas('variant.model', fn ($m) => $m->where('name', 'like', $term))
                    ->orWhereHas('variant.model.brand', fn ($b) => $b->where('name', 'like', $term))
                    ->orWhereHas('color', fn ($c) => $c->where('name', 'like', $term));
            });
        }

        $skus = $query->get();

        $stockBySku = InventoryStock::where('branch_id', $branchId)
            ->whereIn('product_variant_color_id', $skus->pluck('id'))
            ->pluck('quantity', 'product_variant_color_id');

        $imeiCounts = ImeiUnit::where('branch_id', $branchId)
            ->where('status', 'in_stock')
            ->whereIn('product_variant_color_id', $skus->pluck('id'))
            ->selectRaw('product_variant_color_id, COUNT(*) as total')
            ->groupBy('product_variant_color_id')
            ->pluck('total', 'product_variant_color_id');

        $demoCounts = ImeiUnit::where('branch_id', $branchId)
            ->where('status', 'in_stock')
            ->where('is_demo', true)
            ->whereIn('product_variant_color_id', $skus->pluck('id'))
            ->selectRaw('product_variant_color_id, COUNT(*) as total')
            ->groupBy('product_variant_color_id')
            ->pluck('total', 'product_variant_color_id');

        $stockDemoCounts = InventoryStock::where('branch_id', $branchId)
            ->whereIn('product_variant_color_id', $skus->pluck('id'))
            ->pluck('demo_quantity', 'product_variant_color_id');

        // Weighted-average FIFO cost for quantity-based SKUs, from whichever
        // batches still have remaining_quantity > 0 (company-wide — batches
        // aren't branch-scoped, see InventoryService::transferQuantity).
        $avgCostBySku = PurchaseItem::whereIn('product_variant_color_id', $skus->pluck('id'))
            ->where('remaining_quantity', '>', 0)
            ->get(['product_variant_color_id', 'remaining_quantity', 'unit_cost'])
            ->groupBy('product_variant_color_id')
            ->map(function ($batches) {
                $totalQty = $batches->sum('remaining_quantity');

                return $totalQty > 0
                    ? $batches->sum(fn ($b) => $b->remaining_quantity * (float) $b->unit_cost) / $totalQty
                    : 0.0;
            });

        // For IMEI-tracked SKUs the cost is exact (not averaged): each
        // in-stock unit at this branch carries its own purchase cost.
        $avgImeiCostBySku = ImeiUnit::where('branch_id', $branchId)
            ->where('status', 'in_stock')
            ->whereIn('product_variant_color_id', $skus->pluck('id'))
            ->with('purchaseItem')
            ->get()
            ->groupBy('product_variant_color_id')
            ->map(fn ($units) => $units->avg(fn ($u) => (float) $u->purchaseItem->unit_cost));

        $canViewCost = $request->user()->can('reports.view-cost');

        $rows = $skus->map(function (ProductVariantColor $sku) use ($stockBySku, $imeiCounts, $demoCounts, $stockDemoCounts, $avgCostBySku, $avgImeiCostBySku, $canViewCost) {
            $model = $sku->variant->model;
            $imeiTracked = $sku->resolvedImeiTrackingEnabled();
            $quantity = $imeiTracked ? ($imeiCounts[$sku->id] ?? 0) : ($stockBySku[$sku->id] ?? 0);
            $unitCost = $imeiTracked ? ($avgImeiCostBySku[$sku->id] ?? 0.0) : ($avgCostBySku[$sku->id] ?? 0.0);

            return [
                'sku_id' => $sku->id,
                'sku' => $sku->sku,
                'display_name' => sprintf(
                    '%s %s %s (%s)',
                    $model->brand->name,
                    $model->name,
                    $sku->variant->label(),
                    $sku->color->name,
                ),
                'brand' => $model->brand->name,
                'model' => $model->name,
                'variant_label' => $sku->variant->label(),
                'color' => $sku->color->name,
                'product_type' => $model->productType->name,
                'imei_tracking_enabled' => $imeiTracked,
                'quantity' => $quantity,
                'demo_quantity' => $imeiTracked ? ($demoCounts[$sku->id] ?? 0) : ($stockDemoCounts[$sku->id] ?? 0),
                'reorder_level' => $sku->reorder_level,
                'is_low_stock' => $sku->reorder_level > 0 && $quantity <= $sku->reorder_level,
                ...($canViewCost ? [
                    'unit_cost' => round($unitCost, 2),
                    'value' => round($unitCost * $quantity, 2),
                ] : []),
            ];
        })->filter(fn ($row) => ! $request->boolean('low_stock_only') || $row['is_low_stock'])
            ->values();

        return response()->json([
            'data' => $rows,
            ...($canViewCost ? ['total_value' => round((float) $rows->sum('value'), 2)] : []),
        ]);
    }

    public function imeiUnits(Request $request)
    {
        $branchId = $request->integer('branch_id') ?: ($request->user()->branch_id ?? Branch::where('is_main', true)->value('id'));
        $status = $request->string('status')->toString() ?: 'in_stock';

        $units = ImeiUnit::where('branch_id', $branchId)
            ->where('status', $status)
            ->with(['sku.variant.model.brand', 'sku.color'])
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn (ImeiUnit $unit) => [
                'id' => $unit->id,
                'imei1' => $unit->imei1,
                'imei2' => $unit->imei2,
                'status' => $unit->status,
                'is_demo' => $unit->is_demo,
                'purchased_at' => $unit->purchased_at?->toDateString(),
                'sold_at' => $unit->sold_at?->toDateString(),
                'display_name' => sprintf(
                    '%s %s %s (%s)',
                    $unit->sku->variant->model->brand->name,
                    $unit->sku->variant->model->name,
                    $unit->sku->variant->label(),
                    $unit->sku->color->name,
                ),
            ]);

        return response()->json(['data' => $units]);
    }

    public function movements(Request $request)
    {
        $companyId = $request->user()->company_id;

        $request->validate(['sku_id' => ['required', 'integer', Rule::exists('product_variant_colors', 'id')->where('company_id', $companyId)]]);
        $canViewCost = $request->user()->can('reports.view-cost');

        $sku = ProductVariantColor::with(['variant.model.brand', 'color'])->findOrFail($request->integer('sku_id'));

        if ($sku->resolvedImeiTrackingEnabled()) {
            return response()->json([
                'message' => 'This product is IMEI-tracked — use IMEI History instead.',
            ], 422);
        }

        $movements = StockMovement::where('product_variant_color_id', $sku->id)
            ->with('branch')
            ->orderBy('created_at')
            ->get()
            ->map(fn (StockMovement $movement) => [
                'date' => $movement->created_at->toDateTimeString(),
                'type' => $movement->movement_type,
                'branch' => $movement->branch->name,
                'quantity_change' => $movement->quantity_change,
                ...($canViewCost ? ['unit_cost' => $movement->unit_cost] : []),
            ]);

        $model = $sku->variant->model;

        return response()->json([
            'sku' => [
                'id' => $sku->id,
                'display_name' => sprintf(
                    '%s %s %s (%s)',
                    $model->brand->name,
                    $model->name,
                    $sku->variant->label(),
                    $sku->color->name,
                ),
            ],
            'movements' => $movements,
        ]);
    }
}

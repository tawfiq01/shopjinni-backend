<?php

namespace App\Domain\Sales\Http\Controllers;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariantColor;
use App\Domain\Inventory\Models\ImeiUnit;
use App\Domain\Inventory\Models\InventoryStock;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PosSearchController extends Controller
{
    /**
     * Sellable line candidates for the POS cart: every in-stock IMEI unit is
     * its own candidate (so the cashier picks the exact physical phone),
     * while quantity-based SKUs are one candidate carrying the available count.
     */
    public function search(Request $request)
    {
        $term = trim((string) $request->string('q'));
        $branchId = $request->integer('branch_id') ?: ($request->user()->branch_id ?? Branch::where('is_main', true)->value('id'));

        if ($term === '') {
            return response()->json(['data' => []]);
        }

        $like = '%'.$term.'%';
        $candidates = [];

        $matchedSkus = ProductVariantColor::query()
            ->with(['variant.model.brand', 'variant.model.productType', 'color'])
            ->where('is_active', true)
            ->where(function ($q) use ($like) {
                $q->where('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like)
                    ->orWhereHas('variant.model', fn ($m) => $m->where('name', 'like', $like))
                    ->orWhereHas('variant.model.brand', fn ($b) => $b->where('name', 'like', $like))
                    ->orWhereHas('color', fn ($c) => $c->where('name', 'like', $like));
            })
            ->limit(15)
            ->get();

        foreach ($matchedSkus as $sku) {
            if ($sku->resolvedImeiTrackingEnabled()) {
                $units = ImeiUnit::where('product_variant_color_id', $sku->id)
                    ->where('branch_id', $branchId)
                    ->where('status', 'in_stock')
                    ->where(fn ($qq) => $qq
                        ->where('imei1', 'like', $like)
                        ->orWhere('imei2', 'like', $like)
                        ->orWhere('serial_number', 'like', $like)
                    )
                    ->limit(30)
                    ->get();

                // If the term didn't match any specific IMEI, still list every
                // in-stock unit for this SKU (name/brand/model search case).
                if ($units->isEmpty()) {
                    $units = ImeiUnit::where('product_variant_color_id', $sku->id)
                        ->where('branch_id', $branchId)
                        ->where('status', 'in_stock')
                        ->limit(30)
                        ->get();
                }

                foreach ($units as $unit) {
                    $candidates[] = $this->imeiCandidate($sku, $unit);
                }
            } else {
                $stock = InventoryStock::where('branch_id', $branchId)
                    ->where('product_variant_color_id', $sku->id)
                    ->first();

                if ($stock && $stock->quantity > 0) {
                    $candidates[] = $this->quantityCandidate($sku, $stock->quantity, $stock->demo_quantity);
                }
            }
        }

        // Direct IMEI search may match a unit whose SKU text didn't match above.
        $directUnits = ImeiUnit::where('branch_id', $branchId)
            ->where('status', 'in_stock')
            ->where(fn ($q) => $q->where('imei1', 'like', $like)->orWhere('imei2', 'like', $like))
            ->with(['sku.variant.model.brand', 'sku.variant.model.productType', 'sku.color'])
            ->limit(15)
            ->get();

        $seen = collect($candidates)->pluck('imei_unit_id')->filter()->all();
        foreach ($directUnits as $unit) {
            if (in_array($unit->id, $seen, true)) {
                continue;
            }
            $candidates[] = $this->imeiCandidate($unit->sku, $unit);
        }

        return response()->json(['data' => array_slice($candidates, 0, 30)]);
    }

    private function imeiCandidate(ProductVariantColor $sku, ImeiUnit $unit): array
    {
        $model = $sku->variant->model;

        return [
            'kind' => 'imei',
            'imei_unit_id' => $unit->id,
            'product_variant_color_id' => $sku->id,
            'imei1' => $unit->imei1,
            'imei2' => $unit->imei2,
            'is_demo' => $unit->is_demo,
            'display_name' => sprintf(
                '%s %s %s (%s) — IMEI %s',
                $model->brand->name,
                $model->name,
                $sku->variant->label(),
                $sku->color->name,
                $unit->imei1,
            ),
            'selling_price_current' => $sku->selling_price_current === null ? null : (float) $sku->selling_price_current,
        ];
    }

    private function quantityCandidate(ProductVariantColor $sku, int $available, int $demoQuantity = 0): array
    {
        $model = $sku->variant->model;

        return [
            'kind' => 'quantity',
            'imei_unit_id' => null,
            'product_variant_color_id' => $sku->id,
            'display_name' => sprintf(
                '%s %s %s (%s)',
                $model->brand->name,
                $model->name,
                $sku->variant->label(),
                $sku->color->name,
            ),
            'available_quantity' => $available,
            'demo_quantity' => $demoQuantity,
            'selling_price_current' => $sku->selling_price_current === null ? null : (float) $sku->selling_price_current,
        ];
    }
}

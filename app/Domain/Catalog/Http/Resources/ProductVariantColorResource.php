<?php

namespace App\Domain\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantColorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['variant.model.brand', 'variant.model.productType', 'color']);
        $variant = $this->variant;
        $model = $variant->model;

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'unit' => $this->unit,
            'imei_tracking_enabled' => $this->resolvedImeiTrackingEnabled(),
            // Raw override (null = inherits from the model/product type) —
            // distinct from the resolved value above, which callers that just
            // need a yes/no answer (POS, purchasing) should keep using.
            'imei_tracking_override' => $this->imei_tracking_enabled,
            'warranty_months' => $this->warranty_months ?? $model->warranty_months_default,
            'reorder_level' => $this->reorder_level,
            'selling_price_current' => $this->selling_price_current === null ? null : (float) $this->selling_price_current,
            'is_active' => $this->is_active,
            'color' => new ColorResource($this->color),
            'variant' => [
                'id' => $variant->id,
                'ram' => $variant->ram,
                'storage' => $variant->storage,
                'extra_spec' => $variant->extra_spec,
                'label' => $variant->label(),
            ],
            'model' => [
                'id' => $model->id,
                'name' => $model->name,
            ],
            'brand' => [
                'id' => $model->brand->id,
                'name' => $model->brand->name,
            ],
            'product_type' => [
                'id' => $model->productType->id,
                'name' => $model->productType->name,
            ],
            'display_name' => sprintf(
                '%s %s %s (%s) — %s',
                $model->brand->name,
                $model->name,
                $variant->label(),
                $this->color->name,
                $this->sku,
            ),
        ];
    }
}

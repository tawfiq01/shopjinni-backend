<?php

namespace App\Domain\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductModelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'warranty_months_default' => $this->warranty_months_default,
            'imei_tracking_enabled' => $this->imei_tracking_enabled,
            'resolved_imei_tracking_default' => $this->resolvedImeiTrackingDefault(),
            'is_active' => $this->is_active,
            'brand' => new BrandResource($this->whenLoaded('brand')),
            'product_type' => new ProductTypeResource($this->whenLoaded('productType')),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
        ];
    }
}

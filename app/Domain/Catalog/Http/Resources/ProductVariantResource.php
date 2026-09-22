<?php

namespace App\Domain\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ram' => $this->ram,
            'storage' => $this->storage,
            'extra_spec' => $this->extra_spec,
            'label' => $this->label(),
            'is_active' => $this->is_active,
            'colors' => ProductVariantColorResource::collection($this->whenLoaded('colors')),
        ];
    }
}

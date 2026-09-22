<?php

namespace App\Domain\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'imei_tracking_default' => $this->imei_tracking_default,
            'is_active' => $this->is_active,
        ];
    }
}

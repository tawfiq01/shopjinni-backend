<?php

namespace App\Domain\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChartOfAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'parent_id' => $this->parent_id,
            'is_system' => $this->is_system,
            'is_active' => $this->is_active,
            'balance' => $this->balance(),
        ];
    }
}

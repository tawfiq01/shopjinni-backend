<?php

namespace App\Domain\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch?->name,
            'is_active' => $this->is_active,
            'role' => $this->getRoleNames()->first(),
        ];
    }
}

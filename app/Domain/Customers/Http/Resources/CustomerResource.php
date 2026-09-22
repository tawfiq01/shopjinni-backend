<?php

namespace App\Domain\Customers\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mobile' => $this->mobile,
            'address' => $this->address,
            'email' => $this->email,
            'opening_balance' => $this->opening_balance,
            'credit_limit' => $this->credit_limit,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'current_balance' => $this->currentBalance(),
        ];
    }
}

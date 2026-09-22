<?php

namespace App\Domain\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistributorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'company_name' => $this->company_name,
            'contact_person' => $this->contact_person,
            'mobile' => $this->mobile,
            'alt_mobile' => $this->alt_mobile,
            'address' => $this->address,
            'email' => $this->email,
            'opening_balance' => $this->opening_balance,
            'credit_limit' => $this->credit_limit,
            'payment_terms' => $this->payment_terms,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'current_balance' => $this->currentBalance(),
        ];
    }
}

<?php

namespace App\Domain\Expenses\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['category', 'paymentAccount']);

        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'amount' => (float) $this->amount,
            'description' => $this->description,
            'category' => ['id' => $this->category->id, 'name' => $this->category->name],
            'payment_account' => ['id' => $this->paymentAccount->id, 'name' => $this->paymentAccount->name],
        ];
    }
}

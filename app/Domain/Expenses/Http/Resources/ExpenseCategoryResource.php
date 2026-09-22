<?php

namespace App\Domain\Expenses\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'account_code' => $this->account->code,
            'total_spent' => $this->account->balance(),
        ];
    }
}

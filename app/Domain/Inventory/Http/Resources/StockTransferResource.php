<?php

namespace App\Domain\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing([
            'fromBranch',
            'toBranch',
            'items.sku.variant.model.brand',
            'items.sku.color',
            'items.imeiUnit',
        ]);

        return [
            'id' => $this->id,
            'transfer_date' => $this->transfer_date->toDateString(),
            'notes' => $this->notes,
            'from_branch' => ['id' => $this->fromBranch->id, 'name' => $this->fromBranch->name],
            'to_branch' => ['id' => $this->toBranch->id, 'name' => $this->toBranch->name],
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'display_name' => sprintf(
                    '%s %s %s (%s)',
                    $item->sku->variant->model->brand->name,
                    $item->sku->variant->model->name,
                    $item->sku->variant->label(),
                    $item->sku->color->name,
                ),
                'quantity' => $item->quantity,
                'imei' => $item->imeiUnit?->imei1,
            ]),
        ];
    }
}

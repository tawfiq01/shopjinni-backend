<?php

namespace App\Domain\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing([
            'customer',
            'items.sku.variant.model.brand',
            'items.sku.color',
            'items.imeiUnit',
            'payments.paymentMethod',
        ]);

        $canViewCost = $request->user()?->can('reports.view-cost') ?? false;

        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'sale_date' => $this->sale_date->toDateString(),
            'customer' => $this->customer ? ['id' => $this->customer->id, 'name' => $this->customer->name] : null,
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'tax' => (float) $this->tax,
            'total' => (float) $this->total,
            'paid_amount' => (float) $this->paid_amount,
            'due_amount' => (float) $this->due_amount,
            'status' => $this->status,
            $this->mergeWhen($canViewCost, [
                'total_cost' => (float) $this->total_cost,
                'profit' => (float) $this->profit,
            ]),
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'sku' => $item->sku->sku,
                'display_name' => sprintf(
                    '%s %s %s (%s)',
                    $item->sku->variant->model->brand->name,
                    $item->sku->variant->model->name,
                    $item->sku->variant->label(),
                    $item->sku->color->name,
                ),
                'imei' => $item->imeiUnit?->imei1,
                'is_demo' => $item->imeiUnit?->is_demo ?? false,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'discount' => (float) $item->discount,
                'line_total' => (float) $item->line_total,
                ...($canViewCost ? ['unit_cost' => (float) $item->unit_cost, 'profit' => (float) $item->profit] : []),
            ]),
            'payments' => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'method' => $payment->paymentMethod->name,
                'amount' => (float) $payment->amount,
                'paid_at' => $payment->paid_at->toDateString(),
            ]),
        ];
    }
}

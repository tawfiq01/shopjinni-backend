<?php

namespace App\Domain\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['distributor', 'items.sku.variant.model.brand', 'items.sku.color', 'items.imeiUnits', 'payments.paymentMethod']);

        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'purchase_date' => $this->purchase_date->toDateString(),
            'distributor' => [
                'id' => $this->distributor->id,
                'name' => $this->distributor->name,
            ],
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'tax' => (float) $this->tax,
            'total' => (float) $this->total,
            'paid_amount' => (float) $this->paid_amount,
            'due_amount' => (float) $this->due_amount,
            'status' => $this->status,
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
                'quantity' => $item->quantity,
                'remaining_quantity' => $item->remaining_quantity,
                'unit_cost' => (float) $item->unit_cost,
                'discount' => (float) $item->discount,
                'tax' => (float) $item->tax,
                'line_total' => (float) $item->line_total,
                'imeis' => $item->imeiUnits->map(fn ($unit) => [
                    'id' => $unit->id,
                    'imei1' => $unit->imei1,
                    'status' => $unit->status,
                    'is_demo' => $unit->is_demo,
                ]),
            ]),
            'payments' => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'method' => $payment->paymentMethod->name,
                'amount' => (float) $payment->amount,
                'paid_at' => $payment->paid_at->toDateString(),
                'reference_no' => $payment->reference_no,
            ]),
        ];
    }
}

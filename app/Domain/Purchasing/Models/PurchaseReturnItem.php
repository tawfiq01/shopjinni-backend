<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Inventory\Models\ImeiUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_return_id',
        'purchase_item_id',
        'imei_unit_id',
        'quantity',
        'unit_cost',
        'line_total',
    ];

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function imeiUnit(): BelongsTo
    {
        return $this->belongsTo(ImeiUnit::class);
    }
}

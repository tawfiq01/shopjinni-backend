<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\ProductVariantColor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_transfer_id',
        'product_variant_color_id',
        'imei_unit_id',
        'quantity',
    ];

    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductVariantColor::class, 'product_variant_color_id');
    }

    public function imeiUnit(): BelongsTo
    {
        return $this->belongsTo(ImeiUnit::class);
    }
}

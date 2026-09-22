<?php

namespace App\Domain\Sales\Models;

use App\Domain\Catalog\Models\ProductVariantColor;
use App\Domain\Inventory\Models\ImeiUnit;
use App\Domain\Purchasing\Models\PurchaseItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_invoice_id',
        'product_variant_color_id',
        'imei_unit_id',
        'quantity',
        'unit_price',
        'discount',
        'line_total',
        'unit_cost',
        'profit',
        'purchase_item_id',
    ];

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductVariantColor::class, 'product_variant_color_id');
    }

    public function imeiUnit(): BelongsTo
    {
        return $this->belongsTo(ImeiUnit::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }
}

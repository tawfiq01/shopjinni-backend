<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\ProductVariantColor;
use App\Domain\Inventory\Models\ImeiUnit;
use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseItem extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'purchase_invoice_id',
        'product_variant_color_id',
        'quantity',
        'demo_quantity',
        'unit_cost',
        'discount',
        'tax',
        'line_total',
        'warranty_months',
        'remaining_quantity',
    ];

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductVariantColor::class, 'product_variant_color_id');
    }

    public function imeiUnits(): HasMany
    {
        return $this->hasMany(ImeiUnit::class);
    }
}

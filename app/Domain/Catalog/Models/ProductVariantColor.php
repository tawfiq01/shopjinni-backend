<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariantColor extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'product_variant_id',
        'color_id',
        'sku',
        'barcode',
        'unit',
        'imei_tracking_enabled',
        'warranty_months',
        'reorder_level',
        'selling_price_current',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'imei_tracking_enabled' => 'boolean',
            'is_active' => 'boolean',
            'selling_price_current' => 'decimal:2',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * withTrashed: a soft-deleted color must keep resolving so historical
     * sales/purchase line items that reference this SKU keep showing its name.
     */
    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class)->withTrashed();
    }

    /**
     * Effective IMEI-tracking flag: this SKU's own override wins, otherwise
     * fall back through the model to the product type's default. Defaults to
     * false (no tracking) if nothing in the chain specifies it.
     */
    public function resolvedImeiTrackingEnabled(): bool
    {
        return $this->imei_tracking_enabled
            ?? $this->variant->model->resolvedImeiTrackingDefault()
            ?? false;
    }
}

<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductModel extends Model
{
    use HasFactory, BelongsToCompany;

    protected $table = 'product_models';

    protected $fillable = [
        'brand_id',
        'product_type_id',
        'name',
        'warranty_months_default',
        'imei_tracking_enabled',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'imei_tracking_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Effective IMEI-tracking default for SKUs under this model: an explicit
     * override on the model wins, otherwise fall back to the product type's default.
     */
    public function resolvedImeiTrackingDefault(): ?bool
    {
        return $this->imei_tracking_enabled ?? $this->productType?->imei_tracking_default;
    }
}

<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariantColor;
use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    use HasFactory, BelongsToCompany;

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_SALE = 'sale';

    public const TYPE_PURCHASE_RETURN = 'purchase_return';

    public const TYPE_SALES_RETURN = 'sales_return';

    public const TYPE_TRANSFER_OUT = 'transfer_out';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_EXCHANGE_IN = 'exchange_in';

    public const TYPE_EXCHANGE_OUT = 'exchange_out';

    protected $fillable = [
        'branch_id',
        'product_variant_color_id',
        'imei_unit_id',
        'movement_type',
        'quantity_change',
        'unit_cost',
        'reference_type',
        'reference_id',
        'created_by',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductVariantColor::class, 'product_variant_color_id');
    }

    public function imeiUnit(): BelongsTo
    {
        return $this->belongsTo(ImeiUnit::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}

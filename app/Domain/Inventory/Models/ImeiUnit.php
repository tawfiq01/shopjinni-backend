<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariantColor;
use App\Domain\Purchasing\Models\PurchaseItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImeiUnit extends Model
{
    use HasFactory;

    public const STATUS_IN_STOCK = 'in_stock';

    public const STATUS_SOLD = 'sold';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_DAMAGED = 'damaged';

    public const STATUS_EXCHANGED = 'exchanged';

    public const STATUS_WARRANTY_SERVICE = 'warranty_service';

    public const STATUS_LOST = 'lost';

    public const STATUS_TRANSFERRED = 'transferred';

    protected $fillable = [
        'product_variant_color_id',
        'branch_id',
        'purchase_item_id',
        'imei1',
        'imei2',
        'serial_number',
        'status',
        'is_demo',
        'warranty_months',
        'purchased_at',
        'sold_at',
    ];

    protected function casts(): array
    {
        return [
            'is_demo' => 'boolean',
            'purchased_at' => 'date',
            'sold_at' => 'date',
        ];
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductVariantColor::class, 'product_variant_color_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }
}

<?php

namespace App\Domain\Sales\Models;

use App\Domain\Inventory\Models\ImeiUnit;
use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnItem extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'sales_return_id',
        'sale_item_id',
        'imei_unit_id',
        'quantity',
        'condition',
        'restocked',
        'refund_amount',
    ];

    protected function casts(): array
    {
        return ['restocked' => 'boolean'];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function imeiUnit(): BelongsTo
    {
        return $this->belongsTo(ImeiUnit::class);
    }
}

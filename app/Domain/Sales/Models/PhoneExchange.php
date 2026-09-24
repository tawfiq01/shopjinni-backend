<?php

namespace App\Domain\Sales\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Inventory\Models\ImeiUnit;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneExchange extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'sales_invoice_id',
        'old_purchase_item_id',
        'old_imei_unit_id',
        'customer_id',
        'exchange_value',
        'price_difference',
        'created_by',
    ];

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function oldPurchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class, 'old_purchase_item_id');
    }

    public function oldImeiUnit(): BelongsTo
    {
        return $this->belongsTo(ImeiUnit::class, 'old_imei_unit_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

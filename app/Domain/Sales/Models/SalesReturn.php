<?php

namespace App\Domain\Sales\Models;

use App\Domain\Accounting\Models\PaymentMethod;
use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturn extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'sales_invoice_id',
        'return_date',
        'reason',
        'total_refund',
        'refund_method_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['return_date' => 'date'];
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function refundMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'refund_method_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }
}

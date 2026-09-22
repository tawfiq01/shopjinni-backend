<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Accounting\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchasePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_invoice_id',
        'payment_method_id',
        'amount',
        'paid_at',
        'reference_no',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'date'];
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}

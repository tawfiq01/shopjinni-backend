<?php

namespace App\Domain\Sales\Models;

use App\Domain\Accounting\Models\PaymentMethod;
use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePayment extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'sales_invoice_id',
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

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}

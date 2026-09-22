<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Branches\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'distributor_id',
        'invoice_number',
        'purchase_date',
        'subtotal',
        'discount',
        'tax',
        'total',
        'paid_amount',
        'due_amount',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['purchase_date' => 'date'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

<?php

namespace App\Domain\Sales\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Customers\Models\Customer;
use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInvoice extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'branch_id',
        'customer_id',
        'invoice_number',
        'sale_date',
        'subtotal',
        'discount',
        'tax',
        'total',
        'total_cost',
        'profit',
        'paid_amount',
        'due_amount',
        'status',
        'salesperson_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['sale_date' => 'date'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }
}

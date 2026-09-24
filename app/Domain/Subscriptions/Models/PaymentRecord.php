<?php

namespace App\Domain\Subscriptions\Models;

use App\Domain\Companies\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Same reasoning as Subscription — deliberately not BelongsToCompany,
 * always written by a Super Admin acting on someone else's company.
 */
class PaymentRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'subscription_id',
        'plan_id',
        'amount',
        'billing_cycle',
        'method',
        'reference',
        'paid_at',
        'period_start',
        'period_end',
        'recorded_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

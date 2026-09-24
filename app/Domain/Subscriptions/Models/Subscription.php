<?php

namespace App\Domain\Subscriptions\Models;

use App\Domain\Companies\Models\Company;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deliberately does NOT use BelongsToCompany, unlike every other
 * tenant-owned model — same documented exception as App\Models\User.
 * A Subscription is almost always written by a Super Admin acting on
 * SOMEONE ELSE'S company (recording a payment, changing a plan,
 * suspending a shop), not the acting user's own. Scoping it would mean
 * either wrapping every read/write in CurrentCompany::forceFor(), or
 * silently returning zero rows — an explicit where('company_id', $x)
 * does NOT bypass BelongsToCompany's global scope, it still ANDs in
 * whereRaw('1=0') whenever the acting user's own company doesn't match.
 * company_id is always explicit here instead, centralized behind
 * Company::subscription().
 */
class Subscription extends Model
{
    use HasFactory;

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAYMENT_DUE = 'payment_due';

    public const STATUS_GRACE = 'grace';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUSPENDED = 'suspended';

    /** Statuses EnsureSubscriptionActive blocks business routes for. */
    public const BLOCKING_STATUSES = [self::STATUS_GRACE, self::STATUS_EXPIRED, self::STATUS_SUSPENDED];

    protected $fillable = [
        'company_id',
        'plan_id',
        'status',
        'billing_cycle',
        'trial_ends_at',
        'current_period_ends_at',
        'is_lifetime',
        'payment_due_since',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'is_lifetime' => 'boolean',
            'payment_due_since' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function isBlocked(): bool
    {
        return in_array($this->status, self::BLOCKING_STATUSES, true);
    }
}

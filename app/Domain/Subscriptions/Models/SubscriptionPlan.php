<?php

namespace App\Domain\Subscriptions\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A plan belongs to the platform, not any one shop — global, not
 * BelongsToCompany, unlike every tenant-owned model in this app.
 */
class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'monthly_price',
        'yearly_price',
        'max_users',
        'max_products',
        'max_branches',
        'trial_period_days',
        'features',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'yearly_price' => 'decimal:2',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    public function hasFeature(string $key): bool
    {
        return in_array($key, $this->features ?? [], true);
    }

    /** True when $count is still within this plan's limit for $column (null = unlimited). */
    public function withinLimit(string $column, int $count): bool
    {
        $max = $this->{$column};

        return $max === null || $count < $max;
    }
}

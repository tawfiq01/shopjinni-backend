<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes every query on the model to the current company, and auto-fills
 * company_id on create. Fails closed: when no company can be resolved
 * (console context with no authenticated user, or a user who hasn't
 * finished onboarding), the scope returns zero rows rather than skipping
 * the filter — the unsafe direction for a multi-tenant app is "forgot to
 * filter," not "filtered too much."
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            $companyId = CurrentCompany::id();
            $table = $builder->getModel()->getTable();

            if ($companyId === null) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where("{$table}.company_id", $companyId);
        });

        static::creating(function (Model $model) {
            if (! $model->company_id) {
                $model->company_id = CurrentCompany::id();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

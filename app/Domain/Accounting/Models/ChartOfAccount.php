<?php

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends Model
{
    use HasFactory;

    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_EQUITY = 'equity';

    public const TYPE_INCOME = 'income';

    public const TYPE_EXPENSE = 'expense';

    /** Account types whose balance normally increases with a debit. */
    public const DEBIT_NORMAL_TYPES = [self::TYPE_ASSET, self::TYPE_EXPENSE];

    protected $fillable = ['code', 'name', 'type', 'parent_id', 'is_system', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function isDebitNormal(): bool
    {
        return in_array($this->type, self::DEBIT_NORMAL_TYPES, true);
    }

    /**
     * Net balance in the account's own normal-balance direction
     * (positive = a normal balance, negative = an abnormal one).
     */
    public function balance(): float
    {
        $totals = $this->lines()->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')->first();
        $debit = (float) ($totals->total_debit ?? 0);
        $credit = (float) ($totals->total_credit ?? 0);

        return $this->isDebitNormal() ? $debit - $credit : $credit - $debit;
    }
}

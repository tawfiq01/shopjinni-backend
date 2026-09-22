<?php

namespace App\Domain\Customers\Models;

use App\Domain\Accounting\Models\JournalLine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'mobile',
        'address',
        'email',
        'opening_balance',
        'credit_limit',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Outstanding receivable balance (positive = this customer owes us
     * money), derived from every journal line tagged to them as a party.
     */
    public function currentBalance(): float
    {
        $totals = JournalLine::query()
            ->where('party_type', 'customer')
            ->where('party_id', $this->id)
            ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->first();

        return (float) ($totals->total_debit ?? 0) - (float) ($totals->total_credit ?? 0);
    }
}

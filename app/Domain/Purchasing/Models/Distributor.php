<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Accounting\Models\JournalLine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Distributor extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'company_name',
        'contact_person',
        'mobile',
        'alt_mobile',
        'address',
        'email',
        'opening_balance',
        'credit_limit',
        'payment_terms',
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
     * Outstanding payable balance (positive = we owe this distributor money),
     * derived from every journal line tagged to them as a party — not just
     * the opening balance, so it stays correct as purchases/payments post.
     */
    public function currentBalance(): float
    {
        $totals = JournalLine::query()
            ->where('party_type', 'distributor')
            ->where('party_id', $this->id)
            ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->first();

        return (float) ($totals->total_credit ?? 0) - (float) ($totals->total_debit ?? 0);
    }
}

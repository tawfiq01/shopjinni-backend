<?php

namespace App\Domain\Expenses\Models;

use App\Domain\Accounting\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'chart_of_account_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }
}

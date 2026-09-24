<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'from_branch_id',
        'to_branch_id',
        'transfer_date',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['transfer_date' => 'date'];
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }
}

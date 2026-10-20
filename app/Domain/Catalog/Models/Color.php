<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Color extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    protected $fillable = ['name', 'hex_code'];

    public function productVariantColors(): HasMany
    {
        return $this->hasMany(ProductVariantColor::class);
    }
}

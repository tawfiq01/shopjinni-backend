<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_model_id',
        'ram',
        'storage',
        'extra_spec',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'product_model_id');
    }

    public function colors(): HasMany
    {
        return $this->hasMany(ProductVariantColor::class);
    }

    public function label(): string
    {
        return collect([$this->ram, $this->storage, $this->extra_spec])
            ->filter()
            ->implode(' / ');
    }
}

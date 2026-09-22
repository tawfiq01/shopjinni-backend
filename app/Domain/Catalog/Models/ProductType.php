<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductType extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'imei_tracking_default', 'is_active'];

    protected function casts(): array
    {
        return [
            'imei_tracking_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function models(): HasMany
    {
        return $this->hasMany(ProductModel::class);
    }
}

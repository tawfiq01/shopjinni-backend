<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\ProductModel;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_model_id' => ProductModel::factory(),
            'ram' => '8GB',
            'storage' => '128GB',
            'is_active' => true,
        ];
    }
}

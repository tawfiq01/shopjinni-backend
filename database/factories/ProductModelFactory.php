<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\ProductModel;
use App\Domain\Catalog\Models\ProductType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductModel>
 */
class ProductModelFactory extends Factory
{
    protected $model = ProductModel::class;

    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'product_type_id' => ProductType::factory(),
            'name' => fake()->unique()->bothify('Model ###'),
            'warranty_months_default' => 12,
            'is_active' => true,
        ];
    }
}

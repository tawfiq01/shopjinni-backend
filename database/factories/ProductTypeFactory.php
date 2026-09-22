<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\ProductType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductType>
 */
class ProductTypeFactory extends Factory
{
    protected $model = ProductType::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'imei_tracking_default' => false,
            'is_active' => true,
        ];
    }
}

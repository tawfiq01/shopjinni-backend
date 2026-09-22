<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    protected $model = Brand::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'is_active' => true,
        ];
    }
}

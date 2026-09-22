<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Color;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Color>
 */
class ColorFactory extends Factory
{
    protected $model = Color::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->safeColorName(),
            'hex_code' => fake()->hexColor(),
        ];
    }
}

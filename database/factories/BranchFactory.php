<?php

namespace Database\Factories;

use App\Domain\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().' Branch',
            'is_main' => false,
            'is_active' => true,
        ];
    }
}

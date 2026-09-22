<?php

namespace Database\Factories;

use App\Domain\Purchasing\Models\Distributor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Distributor>
 */
class DistributorFactory extends Factory
{
    protected $model = Distributor::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'mobile' => fake()->numerify('01#########'),
            'opening_balance' => 0,
            'is_active' => true,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Domain\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'mobile' => fake()->numerify('01#########'),
            'opening_balance' => 0,
            'is_active' => true,
        ];
    }
}

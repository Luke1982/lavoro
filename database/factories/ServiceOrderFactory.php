<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServiceOrder>
 */
class ServiceOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::pluck('id')->random(),
            'closed_on' => $this->faker->optional(0.5)->dateTimeBetween('-1 year', 'now'),
            'description' => $this->faker->optional(0.5)->paragraph(),
            'signed_by' => $this->faker->optional(0.5)->name(),
        ];
    }
}

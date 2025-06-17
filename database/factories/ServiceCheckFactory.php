<?php

namespace Database\Factories;

use App\Models\ProductType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServiceCheck>
 */
class ServiceCheckFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_type_id' => ProductType::pluck('id')->random(),
            'name' => $this->faker->word(),
            'order' => $this->faker->numberBetween(0, 100),
            'type' => $this->faker->randomElement(['radio', 'checkgroup', 'boolean', 'number', 'text']),
        ];
    }
}

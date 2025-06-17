<?php

namespace Database\Factories;

use App\Models\ServiceCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServiceCheckValue>
 */
class ServiceCheckValueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order' => $this->faker->numberBetween(0, 100),
            'value' => $this->faker->word(),
        ];
    }
}

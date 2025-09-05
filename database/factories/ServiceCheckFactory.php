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
            'name' => $this->faker->word(),
            'order' => $this->faker->numberBetween(0, 100),
            'type' => $this->faker->randomElement(['radio', 'checkgroup', 'boolean', 'number', 'text']),
        ];
    }

    public function configure()
    {
        return $this->afterCreating(function ($serviceCheck) {
            try {
                $ids = ProductType::inRandomOrder()->limit(1)->pluck('id')->all();
                if ($ids) {
                    $serviceCheck->productTypes()->sync($ids);
                }
            } catch (\Throwable $e) {
            }
        });
    }
}

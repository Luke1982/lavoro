<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MaterialUsageUnit>
 */
class MaterialUsageUnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->randomElement([
                'Stuk',
                'Meter',
                'Kilogram',
                'Liter',
                'Vierkante meter',
                'Kubieke meter',
                'Set',
                'Paar',
                'Doos',
                'Rol',
                'Zak',
            ]),
        ];
    }
}

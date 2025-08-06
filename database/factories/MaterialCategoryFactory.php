<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MaterialCategory>
 */
class MaterialCategoryFactory extends Factory
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
                'Bevestigingsmaterialen',
                'Smeermiddelen',
                'Riemen en kettingen',
                'Motoren',
                'Elektrische onderdelen',
                'Hydraulische onderdelen',
                'Pneumatische onderdelen',
                'Verlichting en signalering',
                'Kabels en draden',
                'Filters en afdichtingen',
            ]),
        ];
    }
}

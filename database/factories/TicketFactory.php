<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_id' => \App\Models\Asset::pluck('id')->random(),
            'subject' => $this->faker->sentence(),
            'description' => $this->faker->paragraph(),
            'status' => $this->faker->randomElement(['Open', 'In behandeling', 'Gesloten']),
            'priority' => $this->faker->randomElement(['Laag', 'Normaal', 'Hoog']),
            'closed_on' => $this->faker->optional()->dateTimeBetween('-1 year', 'now'),
        ];
    }
}

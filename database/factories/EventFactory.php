<?php

namespace Database\Factories;

use App\Models\EventType;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(3),
            'description' => null,
            'event_type_id' => EventType::factory(),
            'status' => 'Gepland',
            'start' => now(),
            'end' => now()->addHour(),
            'no_service_order' => false,
        ];
    }
}

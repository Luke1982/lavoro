<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'title' => fake()->company() . ' locatie',
            'location_code' => strtoupper(fake()->bothify('LOC-###')),
            'address' => fake()->streetName() . ' ' . fake()->buildingNumber(),
            'postal_code' => fake()->numerify('####') . strtoupper(fake()->lexify('??')),
            'city' => fake()->city(),
            'country' => 'Nederland',
        ];
    }
}

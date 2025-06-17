<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Assets>
 */
class AssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::pluck('id')->random(),
            'customer_id' => Customer::pluck('id')->random(),
            'serial_number' => $this->faker->unique()->numerify('SN-#####'),
            'next_service_date' => $this->faker->dateTimeBetween('now', '+1 year'),
            'status' => $this->faker->randomElement(['Actief', 'Niet actief']),
        ];
    }
}

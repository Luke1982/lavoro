<?php

namespace Database\Factories;

use App\Enums\ContractInterval;
use App\Models\Customer;
use App\Models\MaintenanceContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceContract>
 */
class MaintenanceContractFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'title' => $this->faker->optional()->sentence(3),
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => null,
            'price' => $this->faker->randomFloat(2, 50, 500),
            'price_interval' => ContractInterval::jaarlijks->value,
            'manage_frequency_per_asset' => false,
            'frequency' => ContractInterval::jaarlijks->value,
            'auto_generate' => false,
        ];
    }
}

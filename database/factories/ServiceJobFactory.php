<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Enums\ServiceJobOutcomes;
use App\Models\ServiceJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServiceJob>
 */
class ServiceJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_id' => Asset::pluck('id')->random(),
            'outcome' => $this->faker->randomElement(array_map(
                fn($case) => $case->value,
                ServiceJobOutcomes::cases()
            )),
            'days_temporary_approval' => $this->faker->numberBetween(0, 30),
            'description' => $this->faker->optional()->sentence(),
            'completed_on' => $this->faker->optional()->date(),
        ];
    }

    public function configure()
    {
        return $this->afterCreating(function (ServiceJob $service_job) {
            \Illuminate\Support\Facades\Log::info('test');
            $checks = $service_job->asset?->product?->productType?->serviceChecks()->get() ?? collect();
            $checks->each(function ($check) use ($service_job) {
                $service_job->checkInstances()->create([
                    'service_check_id' => $check->id,
                ]);
            });
        });
    }
}

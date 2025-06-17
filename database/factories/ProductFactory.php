<?php

namespace Database\Factories;

use App\Models\ProductType;
use App\Enums\ProductBrands;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    protected $fake_models = [
        'TLA-12 Scissor Lift',
        'TLA-22 Heavy-Duty Lift',
        'ACS7000 A/C Service Station',
        'C50 Pro Diagnostic Scanner',
        'HawkEye Elite Alignment System',
        'SPC 9620 Diagnostic Tool',
        'VERUS Pro Scanner',
        'MT2500 Transmission Flush',
        'Power MIG 210 MP',
        'Vantage 500 Welder',
        'Husky 515 Air-Operated Pump',
        'Fel-Mar Single-Line Lubrication System',
        'eAir Series Compressors',
        'Garage Equipment Kit',
        'KTS 940 Diagnostic Scanner',
        'Vehicle Lift VE-7',
        'KONFORT 780R A/C Station',
        'AXONE Nemo Diagnostics Tablet',
        'MERCUR 3 Wheel Balancer',
        'RMX 4500 Tire Changer',
        'RP 513P 2-Post Lift',
        'G822/202HP 4-Post Lift',
        'Mercurio EVO Tire Changer',
        'Cleopatre Wheel Balancer',
        'Artiglio NT Tire Changer',
        'EM 9285 Wheel Balancer',
        'XPR-10AS 2-Post Lift',
        'X-431 PRO3 Scanner',
        'X-513 Wheel Balancer',
        'WB-2600 Wheel Balancer',
        'PP 81 Wheel Balancer',
        'Impact TC-3000 Tire Changer',
        'Geodyna 7700 Balancer',
        'SPOA 10 2-Post Lift',
        'Compact 4000 Mid-Rise Lift',
        'AA-2LP Lift',
        'AW 1S LITE Brake Tester',
        'Mega P6 Frame Machine',
        'FLASH 4 Welding Machine',
        'Eurolift Scissor Lift',
        'GA110 Compressor',
        'XL 880 Wheel Aligner',
        'Wheelmate WP 2300 Balancer',
        'Multipowerfit Changer',
        'AC.net A/C Service Station',
        'MaxiSYS Ultra Diagnostic Tablet',
        'DS180E Diagnostic Scanner',
        'G-Scan 3 Scanner',
        'XP 395 Compressor',
        'R12 Piston Compressor',
        '1234yf A/C Recovery Unit',
        '1430C Grease Pump',
        'CL 10 Four-Post Lift',
        'Ultimate CF Tire Spreader',
        'ERKon Diagnostic System',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_type_id' => ProductType::all()->random()->id,
            'brand' => $this->faker->randomElement(
                array_map(
                    fn($case) => $case->value,
                    ProductBrands::cases()
                )
            ),
            'model' => $this->faker->randomElement($this->fake_models),
            'description' => $this->faker->sentence(),
            'start_sell' => $this->faker->dateTimeBetween('-1 year', '+1 year'),
            'end_sell' => $this->faker->dateTimeBetween('+1 year', '+2 years'),
            ];
    }
}

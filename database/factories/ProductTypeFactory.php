<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductType>
 */
class ProductTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement([
                'Platenbank',
                'Rollenremmentestbank',
                'Pedaaldrukmeter',
                'Viergastester',
                'Roetmeter',
                'Nul emissiekast',
                'Toerenteller',
                'Emissietest toebehoren',
                'Deeltjesteller',
                'Deeltjesteller toebehoren',
                'Koplampafsteller',
                'Aircomachine',
                'Airco toebehoren',
                'Versnellingsbakspoeler',
                'Bandenwisselaar',
                'Bandenwisselaar toebehoren',
                'Wielbalancer',
                'Wielbalancer toebehoren',
                'Uitlijner',
                'Uitlijn toebehoren',
                '1-koloms hefbrug',
                '2-koloms hefbrug',
                '4-koloms hefbrug',
                'Hefbrug toebehoren',
                'Wielvrije schaarhefbrug',
                'Rijbanen schaarhefbrug',
                'Wielvrije stempelhefbrug',
                'Rijbanen stempelhefbrug',
                'Schade/poets hefbrug',
                'Mobiele hefkolom',
                'Brugkrik',
                'Nog onbekende hefbrug',
                'Bandenscanner',
                'TPMS',
                'Appendage apparatuur',
                'Diagnoseapparatuur',
                'ADAS',
                'ADAS toebehoren',
                'Wiellift',
                'Wielenwasser',
                'Compressor',
                'Diverse',
                'Sparepart APK',
                'Sparepart hefinrichtingen',
                'Sparepart wielservice',
                'Sparepart overige',
            ]),
        ];
    }
}

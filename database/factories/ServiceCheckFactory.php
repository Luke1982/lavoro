<?php

namespace Database\Factories;

use App\Models\ProductType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServiceCheck>
 */
class ServiceCheckFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = $this->faker->randomElement(['radio', 'checkgroup', 'boolean', 'number', 'text']);

        return [
            'name' => $this->believableNameForType($type),
            'order' => $this->faker->numberBetween(0, 100),
            'type' => $type,
        ];
    }

    // Seeder controls associations; factory does not auto-link product types.

    private function believableNameForType(string $type): string
    {
        switch ($type) {
            case 'boolean':
                return $this->faker->randomElement([
                    'Noodstop werkt',
                    'CE-markering zichtbaar',
                    'Beveiligingskap aanwezig',
                    'Kabels onbeschadigd',
                    'Lekvrij',
                    'Aarding in orde',
                    'Stroomloos vóór onderhoud uitgevoerd',
                ]);
            case 'number':
                return $this->faker->randomElement([
                    'Bandenspanning (bar)',
                    'Stroom (A)',
                    'Spanning (V)',
                    'Temperatuur (°C)',
                    'Geluidsniveau (dB)',
                    'Speling (mm)',
                    'Toerental (rpm)',
                    'Smeringsniveau (%)',
                ]);
            case 'text':
                return $this->faker->randomElement([
                    'Opmerkingen monteur',
                    'Beschrijving defect',
                    'Locatie opmerking',
                    'Aanvullende instructies',
                    'Serienummer notitie',
                ]);
            case 'radio':
                return $this->faker->randomElement([
                    'Status keuringssticker',
                    'Visuele staat',
                    'Algemene beoordeling',
                    'Smering beoordeling',
                    'Remtest resultaat',
                ]);
            case 'checkgroup':
                return $this->faker->randomElement([
                    'Aanwezige veiligheidsvoorzieningen',
                    'Meegeleverde accessoires',
                    'Benodigde PBM\'s',
                    'Uitgevoerde controles',
                    'Gereedschap aanwezig',
                ]);
            default:
                return 'Controle';
        }
    }
}

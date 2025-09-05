<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServiceCheckGroup>
 */
class ServiceCheckGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(self::defaultGroupNames()),
            'order' => $this->faker->numberBetween(0, 100),
        ];
    }

    public static function defaultGroupNames(): array
    {
        return [
            'Algemene inspectie',
            'Veiligheidsvoorzieningen',
            'Elektrische controle',
            'Mechanische controle',
            'Smering en onderhoud',
            'Documentatie en labels',
        ];
    }
}

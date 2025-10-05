<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use App\Models\Asset;
use App\Models\Ticket;
use App\Models\Product;
use App\Models\EventType;
use App\Models\ServiceJob;
use App\Models\MaterialRole;
use App\Models\ServiceCheck;
use App\Models\ServiceCheckGroup;
use Database\Factories\ServiceCheckGroupFactory;
use App\Models\ServiceOrder;
use App\Models\ProductType;
use Illuminate\Database\Seeder;
use App\Models\MaterialCategory;
use App\Models\MaterialUsageUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;

class DevelopmentSeeder extends Seeder
{
    /**
     * Seed the application's database with development data.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $admin_role = Role::query()->where('name', 'admin')->first();
        if ($admin_role) {
            $user->roles()->syncWithoutDetaching([$admin_role->id]);
        }

        Product::factory(30)->create();
        Artisan::call('snelstart:fetch-relaties');
        Asset::factory(100)->create();
        Ticket::factory(100)->create();

        $groups = collect(ServiceCheckGroupFactory::defaultGroupNames())
            ->map(fn ($name, $index) => ServiceCheckGroup::factory()->create([
                'name' => $name,
                'order' => $index + 1,
            ]));

        ProductType::all()->each(function (ProductType $pt) use ($groups) {
            if ($groups->isNotEmpty()) {
                $pt->serviceCheckGroups()->sync($groups->random(rand(2, min(4, $groups->count()))));
            }
        });

        ProductType::all()->each(function (ProductType $pt) use ($groups) {
            $count = rand(5, 20);
            ServiceCheck::factory($count)->create()->each(function ($serviceCheck) use ($pt, $groups) {
                if ($groups->isNotEmpty()) {
                    $serviceCheck->group()->associate($groups->random());
                    $serviceCheck->save();
                }
                if (in_array($serviceCheck->type, ['radio', 'checkgroup'], true)) {
                    $options = $this->optionValuesForServiceCheck($serviceCheck);
                    $payload = collect($options)->values()->map(function ($text, $idx) {
                        return [
                            'order' => $idx + 1,
                            'value' => $text,
                        ];
                    })->toArray();
                    if (!empty($payload)) {
                        $serviceCheck->values()->createMany($payload);
                    }
                }
                $serviceCheck->productTypes()->syncWithoutDetaching([$pt->id]);
            });
        });

        Model::withoutEvents(function () {
            ServiceOrder::factory()
                ->count(10)
                ->has(ServiceJob::factory()->count(rand(0, 5)))
                ->create();
        });

        Artisan::call('snelstart:fetch-artikelen');
        MaterialCategory::factory(10)->create();
        MaterialUsageUnit::factory(10)->create();
        MaterialRole::factory(4)->create();
        EventType::factory(5)->create();
    }

    private function optionValuesForServiceCheck(\App\Models\ServiceCheck $check): array
    {
        $name = mb_strtolower($check->name);

        $sets = [
            'sticker' => ['Sticker aanwezig', 'Sticker beschadigd', 'Sticker ontbreekt'],
            'visueel' => ['Goed', 'Voldoende', 'Matig', 'Slecht'],
            'algemeen' => ['Uitstekend', 'Goed', 'Redelijk', 'Slecht'],
            'smering' => ['Voldoende', 'Aanvullen', 'Verversen'],
            'rem' => ['Geslaagd', 'Afgekeurd', 'Niet van toepassing'],
            'veiligheid' => ['Noodstop', 'Afscherming', 'Waarschuwingslabels', 'Vergrendeling', 'Aarding'],
            'accessoires' => ['Handleiding', 'Reservedeel', 'Smeermiddel', 'Kabelset', 'Adapter'],
            'pbm' => ['Veiligheidsbril', 'Gehoorbescherming', 'Handschoenen', 'Veiligheidsschoenen', 'Helm'],
            'controles' => ['Visuele inspectie', 'Functietest', 'Lektest', 'Elektrische meting', 'Reiniging'],
            'gereedschap' => ['Sleutelset', 'Momentsleutel', 'Multimeter', 'Vetspuit', 'Kalibreersetje'],
        ];

        $map = [
            'sticker' => $sets['sticker'],
            'keuringssticker' => $sets['sticker'],
            'visuele' => $sets['visueel'],
            'visuele staat' => $sets['visueel'],
            'algemene beoordeling' => $sets['algemeen'],
            'smer' => $sets['smering'],
            'rem' => $sets['rem'],
            'veiligheidsvoorzieningen' => $sets['veiligheid'],
            'accessoires' => $sets['accessoires'],
            'pbm' => $sets['pbm'],
            'persoonlijke beschermingsmiddelen' => $sets['pbm'],
            'controles' => $sets['controles'],
            'gereedschap' => $sets['gereedschap'],
        ];

        foreach ($map as $needle => $values) {
            if (str_contains($name, $needle)) {
                return $this->limitOptionsForType($check->type, $values);
            }
        }

        $generic = $check->type === 'radio'
            ? ['OK', 'Niet OK', 'Niet van toepassing']
            : ['Optie A', 'Optie B', 'Optie C', 'Optie D'];

        $subject = ucfirst($check->name);
        $decorated = array_map(function ($opt) use ($subject) {
            return "$subject – $opt";
        }, $generic);

        return $this->limitOptionsForType($check->type, $decorated);
    }

    private function limitOptionsForType(string $type, array $options): array
    {
        if ($type === 'radio') {
            $count = min(max(3, rand(3, 5)), count($options));
        } else {
            $count = min(max(4, rand(4, 8)), count($options));
        }
        shuffle($options);
        return array_slice($options, 0, $count);
    }
}

<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        $brands = json_decode(file_get_contents(base_path('database/seeders/data/demo/brands.json')), true);
        $models = json_decode(file_get_contents(base_path('database/seeders/data/demo/models.json')), true);
        $product_types = json_decode(file_get_contents(base_path('database/seeders/data/demo/producttypes.json')), true);
        $dutch_addresses = json_decode(file_get_contents(base_path('database/seeders/data/demo/dutch_companies.json')), true);
        $material_usage_units = json_decode(file_get_contents(base_path('database/seeders/data/demo/material_usage_units.json')), true);
        $materials = json_decode(file_get_contents(base_path('database/seeders/data/demo/materials.json')), true);
        // Seed usage units
        $usageUnitModels = [];
        foreach ($material_usage_units as $unit_name) {
            $usageUnitModels[$unit_name] = \App\Models\MaterialUsageUnit::firstOrCreate(['name' => $unit_name]);
        }

        // Seed materials
        foreach ($materials as $mat) {
            $usageUnit = isset($mat['usage_unit']) ? $usageUnitModels[$mat['usage_unit']] ?? null : null;
            \App\Models\Material::firstOrCreate([
                'name' => $mat['name'],
                'description' => $mat['description'] ?? null,
                'code' => $mat['code'] ?? null,
                'vendor_code' => $mat['vendor_code'] ?? null,
                'price' => $mat['price'] ?? 0.00,
                'cost_price' => $mat['cost_price'] ?? 0.00,
                'material_usage_unit_id' => $usageUnit ? $usageUnit->id : null,
                'divisable' => $mat['divisable'] ?? false,
                'is_active' => true,
                'is_service' => $mat['is_service'] ?? false,
                'stock' => $mat['stock'] ?? 0.00,
                'min_stock' => 0.00,
                'max_stock' => 0.00,
            ]);
        }

        foreach ($product_types as $type_name) {
            ProductType::firstOrCreate(['name' => $type_name]);
        }
        foreach ($brands as $brand_name) {
            \App\Models\Brand::firstOrCreate(['name' => $brand_name]);
        }
        foreach ($models as $data) {
            $brand = \App\Models\Brand::where('name', $data['brand'])->first();
            $ptype = ProductType::inRandomOrder()->first();
            if ($brand && $ptype) {
                Product::firstOrCreate([
                    'model' => $data['model'],
                    'brand_id' => $brand->id,
                ], [
                    'product_type_id' => $ptype->id,
                ]);
            }
        }

        $servicechecks = json_decode(file_get_contents(base_path('database/seeders/data/demo/servicechecks.json')), true);
        $servicecheckgroups = json_decode(file_get_contents(base_path('database/seeders/data/demo/servicecheckgroups.json')), true);


        $servicecheckModels = [];
        foreach ($servicechecks as $check) {
            $servicecheckModel = \App\Models\ServiceCheck::firstOrCreate([
                'name' => $check['name'],
                'type' => $check['type'],
            ]);
            $demoProductTypes = \App\Models\ProductType::whereIn('name', $product_types)->get();
            $randomProductTypes = $demoProductTypes->random(rand(1, min(3, $demoProductTypes->count())));
            $servicecheckModel->productTypes()->sync($randomProductTypes->pluck('id')->all());
            $servicecheckModels[$check['name']] = $servicecheckModel;

            // Create ServiceCheckValue records for radio/checkgroup types
            if (in_array($check['type'], ['radio', 'checkgroup']) && isset($check['servicecheckoptions'])) {
                foreach ($check['servicecheckoptions'] as $order => $value) {
                    \App\Models\ServiceCheckValue::firstOrCreate([
                        'service_check_id' => $servicecheckModel->id,
                        'order' => $order,
                        'value' => $value,
                    ]);
                }
            }

            // Ensure each producttype gets at least 10 checks
            foreach ($demoProductTypes as $ptype) {
                $allChecks = collect($servicecheckModels)->values();
                $randomChecks = $allChecks->random(min(10, $allChecks->count()));
                $ptype->serviceChecks()->sync($randomChecks->pluck('id')->all());
            }
        }


        $servicecheckgroupModels = [];
        foreach ($servicecheckgroups as $group) {
            $groupModel = \App\Models\ServiceCheckGroup::firstOrCreate([
                'name' => $group['name'],
            ]);
            $servicecheckgroupModels[$group['name']] = $groupModel;
        }


        $allProductTypes = ProductType::all();
        foreach ($allProductTypes as $ptype) {
            $groupsToAttach = collect($servicecheckgroupModels)->random(rand(1, 2))->pluck('id')->all();
            $ptype->serviceCheckGroups()->sync($groupsToAttach);
        }

        $customers = [];
        foreach ($dutch_addresses as $i => $data) {
            $customers[$i] = \App\Models\Customer::create([
                'name' => $data['name'],
                'address' => $data['address'],
                'postal_code' => $data['postal_code'],
                'city' => $data['city'],
                'email' => strtolower(str_replace(' ', '.', $data['name'])) . '@example.com',
            ]);
        }

        $assets_by_customer = [];
        foreach ($customers as $customer) {
            $assets_by_customer[$customer->id] = [];
            for ($j = 0; $j < rand(3, 5); $j++) {
                $product = \App\Models\Product::inRandomOrder()->first();
                $assets_by_customer[$customer->id][] = \App\Models\Asset::create([
                    'product_id' => $product->id,
                    'customer_id' => $customer->id,
                    'serial_number' => 'SN-' . strtoupper(substr($customer->name, 0, 2)) . rand(10000, 99999),
                    'next_service_date' => now()->addDays(rand(1, 365)),
                    'status' => 'actief',
                ]);
            }
        }

        $ticket_statuses = ['Open', 'In behandeling', 'Gesloten'];
        $ticket_priorities = ['Laag', 'Normaal', 'Hoog'];
        foreach ($assets_by_customer as $customer_id => $assets) {
            foreach ($assets as $asset) {
                for ($k = 0; $k < rand(1, 3); $k++) {
                    \App\Models\Ticket::create([
                        'asset_id' => $asset->id,
                        'subject' => 'Storing ' . ($k + 1) . ' bij ' . $asset->serial_number,
                        'description' => 'Beschrijving van storing ' . ($k + 1) . ' voor asset ' . $asset->serial_number,
                        'status' => $ticket_statuses[array_rand($ticket_statuses)],
                        'priority' => $ticket_priorities[array_rand($ticket_priorities)],
                    ]);
                }
            }
        }

        foreach ($customers as $customer) {
            $customer_assets = $assets_by_customer[$customer->id];
            $customer_tickets = \App\Models\Ticket::whereIn('asset_id', collect($customer_assets)->pluck('id'))->get();
            for ($l = 0; $l < rand(1, 3); $l++) {
                $serviceorder = \App\Models\ServiceOrder::create([
                    'customer_id' => $customer->id,
                    'description' => 'Serviceorder ' . ($l + 1) . ' voor ' . $customer->name,
                ]);
                $used_asset_ids = [];
                for ($m = 0; $m < rand(1, 3); $m++) {
                    $asset = collect($customer_assets)->whereNotIn('id', $used_asset_ids)->random();
                    $used_asset_ids[] = $asset->id;
                    \App\Models\ServiceJob::create([
                        'asset_id' => $asset->id,
                        'service_order_id' => $serviceorder->id,
                        'outcome' => 'Nog geen uitkomst',
                    ]);
                }
                $used_ticket_ids = [];
                for ($n = 0; $n < rand(1, 3); $n++) {
                    $ticket = $customer_tickets->whereNotIn('id', $used_ticket_ids)->random();
                    $used_ticket_ids[] = $ticket->id;
                    $ticket->service_order_id = $serviceorder->id;
                    $ticket->save();
                }
            }
        }

        $event_types = [
            'Periodieke controle' => '#388e3c',
            'Oplossen storing' => '#d32f2f',
            'Controle met storingen' => '#fbc02d',
            'Inventarisatie' => '#7b1fa2',
        ];
        foreach ($event_types as $name => $color) {
            \App\Models\EventType::firstOrCreate([
                'name' => $name,
                'color' => $color,
            ]);
        }

        $admin = \App\Models\User::firstOrCreate(['email' => 'admin@lavoro-fsm.nl'], ['name' => 'Admin', 'password' => bcrypt('password')]);
        $monteur = \App\Models\User::firstOrCreate(['email' => 'monteur@lavoro-fsm.nl'], ['name' => 'Monteur', 'password' => bcrypt('password')]);
        $admin_role = \App\Models\Role::firstOrCreate(['name' => 'admin']);
        $monteur_role = \App\Models\Role::firstOrCreate(['name' => 'Monteur']);
        $admin->roles()->syncWithoutDetaching([$admin_role->id]);
        $monteur->roles()->syncWithoutDetaching([$monteur_role->id]);

        $monteur_permissions = include base_path('database/seeders/data/monteur_permissions.php');
        $permission_ids = DB::table('permissions')->whereIn('name', $monteur_permissions)->pluck('id')->all();
        $monteur_role->permissions()->syncWithoutDetaching($permission_ids);

        $serviceorders = \App\Models\ServiceOrder::all();
        $planned_serviceorder_ids = [];
        foreach ($serviceorders as $serviceorder) {
            if (!in_array($serviceorder->id, $planned_serviceorder_ids)) {
                $event_types_all = \App\Models\EventType::all();
                $random_event_type = $event_types_all->random();
                $day = now()->addDays(rand(1, 365));
                $start_hour = rand(8, 15);
                $start_minute = rand(0, 59);
                $start = $day->copy()->setTime($start_hour, $start_minute);
                $duration = rand(1, min(17 - $start_hour, 3));
                $end_hour = $start_hour + $duration;
                $end_minute = rand(0, 59);
                $end = $day->copy()->setTime($end_hour, $end_minute);
                $event = \App\Models\Event::create([
                    'event_type_id' => $random_event_type->id,
                    'name' => 'Serviceorder gepland',
                    'start' => $start,
                    'end' => $end,
                ]);
                $serviceorder->events()->attach($event->id);
                $event->addExecutingUser($monteur->id);
                $planned_serviceorder_ids[] = $serviceorder->id;
            }
        }
    }
}

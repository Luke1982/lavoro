<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceOrderStage;
use Illuminate\Database\Seeder;

class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Company::firstOrCreate(['is_main' => true], ['name' => tenancy()->tenant->name]);

        $default_flags = [
            'is_plannable_state' => false,
            'is_planned_state' => false,
            'is_closed_state' => false,
            'is_planning_cancelled_state' => false,
            'is_invoiced_state' => false,
            'is_incomplete_state' => false,
        ];

        $stages = [
            ['name' => 'Nieuw', 'order' => 1, 'is_plannable_state' => true],
            ['name' => 'Planning geannuleerd', 'order' => 2, 'is_plannable_state' => true, 'is_planning_cancelled_state' => true],
            ['name' => 'Gepland', 'order' => 3, 'is_planned_state' => true],
            ['name' => 'Niet afgerond', 'order' => 4, 'is_incomplete_state' => true],
            ['name' => 'Gesloten', 'order' => 5, 'is_closed_state' => true],
            ['name' => 'Gefactureerd', 'order' => 6, 'is_invoiced_state' => true],
        ];

        foreach ($stages as $stage) {
            ServiceOrderStage::firstOrCreate(['name' => $stage['name']], array_merge($default_flags, $stage));
        }

        foreach (include base_path('database/seeders/data/tenant_roles.php') as $name => $slug) {
            $role = Role::firstOrCreate(['name' => $name]);

            $names = include base_path("database/seeders/data/{$slug}_permissions.php");

            $role->permissions()->syncWithoutDetaching(
                Permission::whereIn('name', $names)->pluck('id')
            );
        }
    }
}

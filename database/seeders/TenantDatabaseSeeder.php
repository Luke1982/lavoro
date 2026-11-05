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

        foreach (include base_path('database/seeders/data/tenant_stages.php') as $stage) {
            ServiceOrderStage::firstOrCreate(['name' => $stage['name']], array_merge($default_flags, $stage));
        }

        /**
         * The test suite creates roles of its own; those clash by name with
         * these. Stages and the company are always needed.
         */
        if (app()->runningUnitTests()) {
            return;
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

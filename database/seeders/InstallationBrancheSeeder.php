<?php

namespace Database\Seeders;

use App\Models\EventType;
use App\Models\MaterialUsageUnit;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InstallationBrancheSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(['name' => 'admin']);

        $monteur_role = Role::firstOrCreate(['name' => 'Monteur']);
        $this->syncPermissions(
            $monteur_role,
            include base_path('database/seeders/data/monteur_permissions.php')
        );

        $projectleider_role = Role::firstOrCreate(['name' => 'Projectleider']);
        $this->syncPermissions(
            $projectleider_role,
            include base_path('database/seeders/data/projectleider_permissions.php')
        );

        $projectmanager_role = Role::firstOrCreate(['name' => 'Projectmanager']);
        $this->syncPermissions(
            $projectmanager_role,
            include base_path('database/seeders/data/projectmanager_permissions.php')
        );

        $planner_role = Role::firstOrCreate(['name' => 'Planner']);
        $this->syncPermissions(
            $planner_role,
            include base_path('database/seeders/data/planner_permissions.php')
        );

        $binnendienst_role = Role::firstOrCreate(['name' => 'Binnendienst']);
        $this->syncPermissions(
            $binnendienst_role,
            include base_path('database/seeders/data/binnendienst_permissions.php')
        );

        $administratie_role = Role::firstOrCreate(['name' => 'Administratie']);
        $this->syncPermissions(
            $administratie_role,
            include base_path('database/seeders/data/administratie_permissions.php')
        );

        $event_types = [
            'Periodieke controle' => '#388e3c',
            'Oplossen storing' => '#d32f2f',
            'Controle met storingen' => '#fbc02d',
            'Inventarisatie' => '#7b1fa2',
        ];
        foreach ($event_types as $name => $color) {
            EventType::firstOrCreate([
                'name' => $name,
                'color' => $color,
            ]);
        }

        $usage_units = include base_path('database/seeders/data/general/usage_units.php');
        foreach ($usage_units as $name) {
            MaterialUsageUnit::firstOrCreate(['name' => $name]);
        }
    }

    protected function syncPermissions(Role $role, array $permission_names): void
    {
        $permission_ids = DB::table('permissions')
            ->whereIn('name', $permission_names)
            ->pluck('id')
            ->all();

        if (!empty($permission_ids)) {
            $role->permissions()->syncWithoutDetaching($permission_ids);
        }
    }
}

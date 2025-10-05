<?php

namespace Database\Seeders;

use App\Enums\Automotive\ProductTypes as ProductTypesEnum;
use App\Enums\Automotive\ProductBrands;
use App\Models\ProductType;
use App\Models\Brand;
use App\Models\Role;
use App\Models\User;
use App\Models\ServiceCheck;
use App\Models\ServiceCheckGroup;
use App\Models\MaterialCategory;
use App\Models\MaterialUsageUnit;
use Illuminate\Database\Seeder;

class AutomotiveEquipmentSeeder extends Seeder
{
    protected function seedGroups(array $groups, $product_types, array $check_values): void
    {
        foreach ($groups as $group_def) {
            $group = ServiceCheckGroup::query()->firstOrCreate([
                'name' => $group_def['name'],
            ], [
                'order' => $group_def['order'],
            ]);

            $ids = collect($product_types)->pluck('id')->all();
            if (!empty($ids)) {
                $group->productTypes()->syncWithoutDetaching($ids);
            }

            $order = 1;
            foreach ($group_def['items'] as $item_def) {
                $item = is_string($item_def) ? ['name' => $item_def, 'type' => 'radio'] : $item_def;
                $check = ServiceCheck::query()->firstOrCreate([
                    'name' => $item['name'],
                    'service_check_group_id' => $group->id,
                ], [
                    'type' => $item['type'] ?? 'radio',
                    'order' => $order,
                ]);

                $values = $item['values'] ?? ($check_values[$check->type] ?? null);
                if ($check->values()->count() === 0 && !empty($values)) {
                    $check->values()->createMany(array_map(function ($v, $i) {
                        return ['order' => $i + 1, 'value' => $v];
                    }, $values, array_keys($values)));
                }

                if (!empty($ids)) {
                    $check->productTypes()->syncWithoutDetaching($ids);
                }

                $order++;
            }
        }
    }

    public function run(): void
    {
        foreach (ProductTypesEnum::cases() as $case) {
            ProductType::query()->firstOrCreate([
                'name' => $case->value,
            ], [
                'typical_certificate_days' => 365,
            ]);
        }

        foreach (ProductBrands::cases() as $brand) {
            Brand::query()->firstOrCreate([
                'name' => $brand->value,
            ]);
        }

        $category_names = include base_path('database/seeders/data/automotiveequipment/material_categories.php');
        foreach ($category_names as $name) {
            MaterialCategory::query()->firstOrCreate(['name' => $name]);
        }

        $usage_units = include base_path('database/seeders/data/general/usage_units.php');
        foreach ($usage_units as $name) {
            MaterialUsageUnit::query()->firstOrCreate(['name' => $name]);
        }

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('password'),
            ]
        );

        $admin_role = Role::query()->firstOrCreate(['name' => 'admin']);
        $admin->roles()->syncWithoutDetaching([$admin_role->id]);

        $monteur_role = Role::query()->firstOrCreate(['name' => 'Monteur']);

        $names = include base_path('database/seeders/data/monteur_permissions.php');
        $permission_ids = app('db')->table('permissions')->whereIn('name', $names)->pluck('id')->all();
        if (!empty($permission_ids)) {
            $monteur_role->permissions()->syncWithoutDetaching($permission_ids);
        }

        $groups = include base_path('database/seeders/data/automotiveequipment/servicechecks/hefbrug.php');

        $hefbrug_names = collect(ProductTypesEnum::cases())
            ->map(fn($c) => $c->value)
            ->filter(function ($n) {
                $lower = mb_strtolower($n);
                return str_contains($lower, 'hefbrug') || str_contains($lower, 'hefkolom');
            })
            ->values();

        $hefbrug_types = ProductType::query()->whereIn('name', $hefbrug_names)->get();

        $this->seedGroups(
            $groups,
            $hefbrug_types,
            [
                'radio' => ['OK', 'Niet OK', 'N.v.t.'],
            ]
        );

        $wb_groups = include base_path('database/seeders/data/automotiveequipment/servicechecks/wielbalancer.php');
        $wb_type = ProductType::query()->where('name', ProductTypesEnum::wielbalancer->value)->first();
        if ($wb_type) {
            $this->seedGroups(
                $wb_groups,
                collect([$wb_type]),
                [
                    'radio' => ['Ja', 'Nee', 'Goed', 'Niet van toepassing'],
                ]
            );
        }

        $bw_groups = include base_path('database/seeders/data/automotiveequipment/servicechecks/bandenwisselaar.php');
        $bw_type = ProductType::query()->where('name', ProductTypesEnum::bandenwisselaar->value)->first();
        if ($bw_type) {
            $this->seedGroups(
                $bw_groups,
                collect([$bw_type]),
                [
                    'radio' => ['Ja', 'Nee', 'Leeg', 'Goed'],
                ]
            );
        }

        $ul_groups = include base_path('database/seeders/data/automotiveequipment/servicechecks/uitlijner.php');
        $ul_type = ProductType::query()->where('name', ProductTypesEnum::uitlijner->value)->first();
        if ($ul_type) {
            $this->seedGroups(
                $ul_groups,
                collect([$ul_type]),
                [
                    'radio' => ['Ja', 'Nee', 'N.v.t.'],
                ]
            );
        }
    }
}

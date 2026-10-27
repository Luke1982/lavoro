<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'maintenancecontract.read', 'label' => 'Onderhoudscontracten bekijken'],
        ['name' => 'maintenancecontract.create', 'label' => 'Onderhoudscontracten aanmaken'],
        ['name' => 'maintenancecontract.update', 'label' => 'Onderhoudscontracten wijzigen'],
        ['name' => 'maintenancecontract.delete', 'label' => 'Onderhoudscontracten verwijderen'],
        ['name' => 'assetable.create.maintenancecontract', 'label' => 'Machine aan onderhoudscontract koppelen'],
        ['name' => 'assetable.update.maintenancecontract', 'label' => 'Machinefrequentie op onderhoudscontract bijwerken'],
        ['name' => 'assetable.delete.maintenancecontract', 'label' => 'Machine van onderhoudscontract loskoppelen'],
    ];

    public function up(): void
    {
        foreach ($this->permissions as $permission) {
            if (! Permission::where('name', $permission['name'])->exists()) {
                Permission::create($permission);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::where('name', $permission['name'])->delete();
        }
    }
};

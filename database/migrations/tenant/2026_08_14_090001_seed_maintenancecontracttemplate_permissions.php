<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'maintenancecontracttemplate.read', 'label' => 'Contractsjablonen bekijken'],
        ['name' => 'maintenancecontracttemplate.create', 'label' => 'Contractsjablonen aanmaken'],
        ['name' => 'maintenancecontracttemplate.update', 'label' => 'Contractsjablonen wijzigen'],
        ['name' => 'maintenancecontracttemplate.delete', 'label' => 'Contractsjablonen verwijderen'],
    ];

    public function up(): void
    {
        foreach ($this->permissions as $permission) {
            if (!Permission::where('name', $permission['name'])->exists()) {
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

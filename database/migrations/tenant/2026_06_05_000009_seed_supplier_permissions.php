<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'supplier.read',   'label' => 'Leveranciers bekijken'],
        ['name' => 'supplier.create', 'label' => 'Leveranciers aanmaken'],
        ['name' => 'supplier.update', 'label' => 'Leveranciers wijzigen'],
        ['name' => 'supplier.delete', 'label' => 'Leveranciers verwijderen'],
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

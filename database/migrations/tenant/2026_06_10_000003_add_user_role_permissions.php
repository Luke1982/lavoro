<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'userrole.read', 'label' => 'Gebruikersrollen bekijken'],
        ['name' => 'userrole.create', 'label' => 'Gebruikersrollen aanmaken'],
        ['name' => 'userrole.update', 'label' => 'Gebruikersrollen wijzigen'],
        ['name' => 'userrole.delete', 'label' => 'Gebruikersrollen verwijderen'],
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

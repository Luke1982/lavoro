<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'contact.read',   'label' => 'Contacten bekijken'],
        ['name' => 'contact.create', 'label' => 'Contacten aanmaken'],
        ['name' => 'contact.update', 'label' => 'Contacten wijzigen'],
        ['name' => 'contact.delete', 'label' => 'Contacten verwijderen'],
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

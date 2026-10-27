<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'materialusageunit.read',   'label' => 'Gebruikseenheden bekijken'],
        ['name' => 'materialusageunit.create', 'label' => 'Gebruikseenheden aanmaken'],
        ['name' => 'materialusageunit.update', 'label' => 'Gebruikseenheden wijzigen'],
        ['name' => 'materialusageunit.delete', 'label' => 'Gebruikseenheden verwijderen'],
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

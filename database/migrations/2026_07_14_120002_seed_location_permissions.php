<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'location.read', 'label' => 'Locaties bekijken'],
        ['name' => 'location.create', 'label' => 'Locaties aanmaken'],
        ['name' => 'location.update', 'label' => 'Locaties wijzigen'],
        ['name' => 'location.delete', 'label' => 'Locaties verwijderen'],
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

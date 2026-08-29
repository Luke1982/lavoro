<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;

return new class extends Migration {
    private array $permissions = [
        ['name' => 'serviceordertaskinstance.read',       'label' => 'Taakinstantie zien'],
        ['name' => 'serviceordertaskinstance.create',     'label' => 'Taakinstantie aanmaken'],
        ['name' => 'serviceordertaskinstance.update',     'label' => 'Taakinstantie bewerken'],
        ['name' => 'serviceordertaskinstance.open_close', 'label' => 'Taakinstantie openen / sluiten'],
        ['name' => 'serviceordertaskinstance.delete',     'label' => 'Taakinstantie verwijderen'],
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

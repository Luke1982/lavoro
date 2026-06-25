<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;

return new class extends Migration {
    private array $permissions = [
        ['name' => 'serviceordertask.read',   'label' => 'Taken zien'],
        ['name' => 'serviceordertask.create', 'label' => 'Taak aanmaken'],
        ['name' => 'serviceordertask.update', 'label' => 'Taak bewerken'],
        ['name' => 'serviceordertask.delete', 'label' => 'Taak verwijderen'],
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

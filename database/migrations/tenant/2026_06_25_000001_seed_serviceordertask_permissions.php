<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;

return new class extends Migration {
    private array $permissions = [
        ['name' => 'serviceordertask.read',   'label' => 'Werkbontaken zien'],
        ['name' => 'serviceordertask.create', 'label' => 'Werkbontaak aanmaken'],
        ['name' => 'serviceordertask.update', 'label' => 'Werkbontaak bewerken'],
        ['name' => 'serviceordertask.delete', 'label' => 'Werkbontaak verwijderen'],
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

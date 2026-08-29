<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;

return new class extends Migration {
    private array $permissions = [
        ['name' => 'serviceorderstage.read',   'label' => 'Werkbonfase zien'],
        ['name' => 'serviceorderstage.create', 'label' => 'Werkbonfase aanmaken'],
        ['name' => 'serviceorderstage.update', 'label' => 'Werkbonfase bijwerken'],
        ['name' => 'serviceorderstage.delete', 'label' => 'Werkbonfase verwijderen'],
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

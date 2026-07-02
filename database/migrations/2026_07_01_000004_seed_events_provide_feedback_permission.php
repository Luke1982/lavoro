<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'events.provide_feedback', 'label' => 'Mag terugkoppeling geven op afspraken'],
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

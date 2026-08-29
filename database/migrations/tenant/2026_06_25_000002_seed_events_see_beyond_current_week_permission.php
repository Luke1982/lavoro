<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'event.see_beyond_current_week', 'label' => 'Mag afspraken zien voorbij de huidige week'],
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

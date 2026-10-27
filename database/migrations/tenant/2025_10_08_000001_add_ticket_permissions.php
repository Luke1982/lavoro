<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;

return new class extends Migration
{
    private array $permissions = [
        [
            'name' => 'ticket.alter_priority',
            'label' => 'Storing prioriteit wijzigen',
        ],
        [
            'name' => 'ticket.change_status',
            'label' => 'Storing status wijzigen',
        ],
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

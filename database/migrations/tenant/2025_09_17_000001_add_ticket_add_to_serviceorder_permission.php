<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;

return new class extends Migration
{
    private array $permissions = [
        [
            'name' => 'ticket.add_to_serviceorder',
            'label' => 'Storing aan werkbon koppelen',
        ],
        [
            'name' => 'ticket.detach_from_serviceorder',
            'label' => 'Storing loskoppelen van werkbon',
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

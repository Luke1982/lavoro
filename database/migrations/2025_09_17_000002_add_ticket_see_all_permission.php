<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;

return new class extends Migration
{
    private array $permission = [
        'name' => 'ticket.see_all',
        'label' => 'Alle storingen bekijken',
    ];

    public function up(): void
    {
        if (!Permission::where('name', $this->permission['name'])->exists()) {
            Permission::create($this->permission);
        }
    }

    public function down(): void
    {
        Permission::where('name', $this->permission['name'])->delete();
    }
};

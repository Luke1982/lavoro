<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private $permission = [
        'name' => 'event.see_all',
        'label' => 'Alle afspraken zien',
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

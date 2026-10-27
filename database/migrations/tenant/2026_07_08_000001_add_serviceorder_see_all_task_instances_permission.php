<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permission = [
        'name' => 'serviceorder.see_all_task_instances',
        'label' => 'Alle taakinstanties zien, ongeacht rolbeperking',
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

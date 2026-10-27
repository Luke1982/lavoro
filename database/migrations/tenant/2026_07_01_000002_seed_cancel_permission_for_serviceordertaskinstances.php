<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (!Permission::where('name', 'serviceordertaskinstance.cancel')->exists()) {
            Permission::create([
                'name'  => 'serviceordertaskinstance.cancel',
                'label' => 'Taakinstantie annuleren',
            ]);
        }
    }

    public function down(): void
    {
        Permission::where('name', 'serviceordertaskinstance.cancel')->delete();
    }
};

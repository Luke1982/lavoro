<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (! Permission::where('name', 'serviceorder.plan')->exists()) {
            Permission::create([
                'name'  => 'serviceorder.plan',
                'label' => 'Werkbonnen inplannen',
            ]);
        }
    }

    public function down(): void
    {
        Permission::where('name', 'serviceorder.plan')->delete();
    }
};

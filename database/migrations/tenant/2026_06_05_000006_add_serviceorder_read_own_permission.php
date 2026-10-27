<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (! Permission::where('name', 'serviceorder.read_own')->exists()) {
            Permission::create([
                'name'  => 'serviceorder.read_own',
                'label' => 'Eigen werkbonnen zien',
            ]);
        }
    }

    public function down(): void
    {
        Permission::where('name', 'serviceorder.read_own')->delete();
    }
};

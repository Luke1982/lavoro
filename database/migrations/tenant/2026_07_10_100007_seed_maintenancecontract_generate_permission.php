<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (! Permission::where('name', 'maintenancecontract.generate')->exists()) {
            Permission::create([
                'name' => 'maintenancecontract.generate',
                'label' => 'Werkbonnen genereren vanuit onderhoudscontract',
            ]);
        }
    }

    public function down(): void
    {
        Permission::where('name', 'maintenancecontract.generate')->delete();
    }
};

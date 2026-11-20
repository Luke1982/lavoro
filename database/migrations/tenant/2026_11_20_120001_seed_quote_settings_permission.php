<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (!Permission::where('name', 'quote.manage_settings')->exists()) {
            Permission::create(['name' => 'quote.manage_settings', 'label' => 'Offerte-instellingen beheren (zoals eenheden)']);
        }
    }

    public function down(): void
    {
        Permission::where('name', 'quote.manage_settings')->delete();
    }
};

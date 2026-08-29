<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Permission::create(['name' => 'documentcategory.manage', 'label' => 'Documentcategorieën beheren']);
    }

    public function down(): void
    {
        Permission::where('name', 'documentcategory.manage')->delete();
    }
};

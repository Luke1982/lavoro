<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('permissionables', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Permission::class)->constrained()->cascadeOnDelete();
            $table->morphs('permissionable');
            $table->timestamps();

            $table->unique(['permission_id', 'permissionable_type', 'permissionable_id'], 'permissionables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissionables');
    }
};

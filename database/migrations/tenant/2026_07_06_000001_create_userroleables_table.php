<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('userroleables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_role_id')->constrained('user_roles')->cascadeOnDelete();
            $table->morphs('userroleable');
            $table->timestamps();
            $table->unique(['user_role_id', 'userroleable_id', 'userroleable_type'], 'userroleables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('userroleables');
    }
};

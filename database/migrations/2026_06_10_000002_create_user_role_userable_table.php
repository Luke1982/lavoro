<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_role_userable', function (Blueprint $table) {
            $table->id();
            $table->foreignId('userable_id')->constrained('userables')->cascadeOnDelete();
            $table->foreignId('user_role_id')->constrained('user_roles')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['userable_id', 'user_role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_userable');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\User;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('userables', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->morphs('userable');
            $table->string('type');
            $table->timestamps();
            $table->unique(['user_id','userable_type','userable_id','type']);
            $table->index(['type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('userables');
    }
};

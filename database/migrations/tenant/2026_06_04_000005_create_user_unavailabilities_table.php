<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_unavailabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\User::class)->constrained()->cascadeOnDelete();
            $table->enum('type', ['recurring', 'holiday']);
            $table->string('label')->nullable();
            $table->tinyInteger('day_of_week')->nullable(); // 0=Mon, 6=Sun
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->enum('repeat', ['weekly', 'biweekly'])->nullable();
            $table->date('reference_date')->nullable(); // biweekly anchor
            $table->date('date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_unavailabilities');
    }
};

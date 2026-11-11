<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A route from the map, put in one person's day: at the top of that day in the
 * planner, and as an all-day item in their Google calendar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained();
            $table->date('date');
            $table->json('stops');
            $table->string('finish', 5);
            $table->json('links');
            $table->json('google_items')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_routes');
    }
};

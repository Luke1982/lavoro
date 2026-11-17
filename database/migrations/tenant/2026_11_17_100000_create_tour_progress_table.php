<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which parts of a rondleiding somebody has seen, and in which version.
 *
 * Kept on the server and not in the browser, so what was watched on the office
 * computer counts on the phone too. One row per person per rondleiding; `seen`
 * maps each chapter to the version that was watched, so a chapter rewritten
 * since then is offered again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('tour', 64);
            $table->timestamp('started_at')->nullable();
            $table->json('seen')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_progress');
    }
};

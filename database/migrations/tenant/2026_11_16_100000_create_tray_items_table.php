<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Het bakje: what a person has set aside to paste somewhere else.
 *
 * Kept per user and on the server, so something put on the bakje on a phone is
 * there to paste on the computer at the office.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tray_items', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->morphs('trayable');
            $table->boolean('pinned')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'trayable_type', 'trayable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tray_items');
    }
};

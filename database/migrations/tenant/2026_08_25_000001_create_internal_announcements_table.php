<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->boolean('is_for_everyone')->default(false);
            $table->date('expires_on')->nullable();
            $table->timestamps();
            $table->index('expires_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_announcements');
    }
};

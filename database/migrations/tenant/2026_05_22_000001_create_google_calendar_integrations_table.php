<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('google_account_email');
            $table->string('google_account_sub');
            $table->text('access_token');
            $table->text('refresh_token');
            $table->dateTime('expires_at');
            $table->json('scopes');
            $table->unsignedInteger('backfill_total')->nullable();
            $table->unsignedInteger('backfill_done')->nullable();
            $table->dateTime('connected_at');
            $table->text('last_error')->nullable();
            $table->dateTime('disabled_at')->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_integrations');
    }
};

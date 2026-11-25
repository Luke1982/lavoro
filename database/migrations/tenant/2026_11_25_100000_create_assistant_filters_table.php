<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time filters the assistant hands out as a link to an overview page:
     * "/serviceorders?filter=<uuid>" shows exactly the records an answer counted.
     *
     * The conditions are stored, not the ids they matched, so whoever opens the
     * link sees their own share of the records through the page's own rules.
     * Kept as long as the assistant's conversations (assistant.keep_months), then
     * removed nightly by PruneAssistantFiltersJob or with assistant:prune.
     */
    public function up(): void
    {
        Schema::create('assistant_filters', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->json('conditions');
            $table->string('summary', 1000);
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_filters');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What one call to the model cost.
     *
     * A row per API call rather than per question, because one question becomes
     * several calls once tools are involved and every one of them is billed.
     *
     * Cost is kept in millionths of a euro. A question costs about a cent, so
     * rounding each row to whole cents would throw away most of it — cents are
     * for invoices, not for meters.
     *
     * All four token counts are stored and all four are needed: input_tokens is
     * only the part of the prompt that was not cached, and cached tokens carry
     * their own rates in both directions. Recording just the first would
     * under-count badly the day caching is switched on.
     *
     * The rates applied are written onto the row too, so a price change or a
     * currency move never reaches backwards into a month already counted.
     *
     * Lives in the tenant database for now and moves to the central one with
     * tenancy, where the allowance gets enforced.
     */
    public function up(): void
    {
        Schema::create('assistant_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('model');

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);

            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->unsignedBigInteger('cost_usd_micros')->default(0);
            $table->decimal('eur_per_usd', 10, 6);

            $table->timestamps();

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_usage');
    }
};

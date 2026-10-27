<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The record of what the assistant was asked to do, kept separately from the
     * activity log on purpose.
     *
     * Activities describe business facts and are read by people on a timeline.
     * This describes attempts — including the ones that were refused, errored, or
     * never changed anything — and answers "what did it try to do".
     */
    public function up(): void
    {
        Schema::create('assistant_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tool')->index();

            /** The model's own id for this call, so a transcript can be tied back to it. */
            $table->string('external_id')->nullable();

            $table->json('arguments')->nullable();
            $table->string('outcome')->index();
            $table->text('result')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_tool_calls');
    }
};

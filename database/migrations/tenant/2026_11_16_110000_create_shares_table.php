<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Something that lives on one record, made visible somewhere else: a project's
 * photo in the work orders of that project.
 *
 * The row names what is shared and where it is shared from, not who receives
 * it. Who receives it follows from the source, so a work order made after the
 * share sees it as well. `access` is what the receiving side may do with it.
 *
 * The unique index starts with the shareable, so it also serves every lookup by
 * what is shared; the source gets an index of its own through morphs().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shares', function (Blueprint $table) {
            $table->id();
            $table->string('shareable_type');
            $table->unsignedBigInteger('shareable_id');
            $table->morphs('source');
            $table->string('access', 20)->default('view');
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['shareable_type', 'shareable_id', 'source_type', 'source_id'], 'shares_shareable_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shares');
    }
};

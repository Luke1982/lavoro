<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links someone without an account may open.
 *
 * Only the hash is stored, never the link itself: whoever reads the database
 * holds nothing that opens a door. Sha256 and not bcrypt, because it is
 * searched on and the value is long enough to make guessing pointless -- the
 * same trade-off Laravel makes for personal access tokens.
 *
 * What the link is about is stored as a morph, and what it is for as a separate
 * key. Those two together make the table indifferent to whatever comes next: a
 * next kind of link is an enum case, not a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');

            /** AccessTokenPurpose, bijvoorbeeld 'ticket.customer_upload'. */
            $table->string('purpose');

            $table->string('token_hash')->unique();

            /** The address the link went to; comes back as a name in the timeline. */
            $table->string('recipient')->nullable();

            /** Wat dit soort link nodig heeft om te weten; per soort anders. */
            $table->json('payload')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->foreignIdFor(User::class, 'revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignIdFor(User::class, 'created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /** "Which links are still open on this record", named by hand because the generated name is too long. */
            $table->index(['tokenable_type', 'tokenable_id', 'purpose'], 'access_tokens_tokenable_purpose_index');

            /** For cleaning up what has expired. */
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_tokens');
    }
};

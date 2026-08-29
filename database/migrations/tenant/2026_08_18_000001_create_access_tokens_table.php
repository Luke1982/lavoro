<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links die iemand zonder account mag openen.
 *
 * Alleen de hash staat er, nooit de link zelf: wie de database leest houdt dan
 * niets in handen dat een deur opent. Sha256 en geen bcrypt, want er wordt op
 * gezocht en de waarde is lang genoeg om raden zinloos te maken — dezelfde
 * afweging die Laravel voor persoonlijke tokens maakt.
 *
 * Waar de link over gaat staat als morph, en waarvoor hij bedoeld is als losse
 * sleutel. Die twee samen maken de tabel onverschillig voor wat er nog bij komt:
 * een volgend soort link is een enum-case, geen kolom.
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

            /** Het adres waar de link naartoe ging; komt terug als naam in de tijdlijn. */
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

            /** "Welke links lopen er nog op dit record", met de hand benoemd omdat de gegenereerde naam te lang is. */
            $table->index(['tokenable_type', 'tokenable_id', 'purpose'], 'access_tokens_tokenable_purpose_index');

            /** Voor het opruimen van wat verlopen is. */
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_tokens');
    }
};

<?php

use App\Models\QuoteLostReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a professional quote states besides its lines, and what follows it once
 * it is out:
 *
 * - version: the version being worked on. Whoever makes the quote decides when
 *   a new one starts; sending the same version again keeps its number. Each send
 *   is still a row of its own in quote_versions, so the number is no longer
 *   unique there.
 * - is_consumer: a private customer, who gets 14 days to change their mind.
 * - layout: which columns the customer sees, when this quote differs from the
 *   settings.
 * - pricing_method, start_period, duration, payment_terms, payment_schedule:
 *   what the installation terms (NVKL, AVIC) require an offer to say.
 * - lost_reason_id and lost_reason_note: why it was declined.
 * - accepted_terms_name and starts_within_cooling_off: what the customer agreed
 *   to along with the quote.
 * - expiry_noticed_for: the end date the owner was last warned about.
 * - origin: the werkbon, storing, quote or template it was made from.
 *
 * On a line, is_provisional marks a stelpost, settled afterwards at the real
 * cost, choice_group binds alternatives of which the customer takes one, and
 * location_note says where it goes: "woonkamer", "slaapkamers klein".
 * On a version, viewed_at and reminded_at say what happened to that send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('number');
            $table->boolean('is_consumer')->default(false)->after('customer_picks_options');
            $table->json('layout')->nullable()->after('is_consumer');
            $table->string('pricing_method', 20)->default('Vaste prijs')->after('layout');
            $table->string('start_period')->nullable()->after('pricing_method');
            $table->string('duration')->nullable()->after('start_period');
            $table->text('payment_terms')->nullable()->after('duration');
            $table->json('payment_schedule')->nullable()->after('payment_terms');
            $table->foreignIdFor(QuoteLostReason::class, 'lost_reason_id')->nullable()->after('decided_at')
                ->constrained('quote_lost_reasons')->nullOnDelete();
            $table->text('lost_reason_note')->nullable()->after('lost_reason_id');
            $table->string('accepted_terms_name')->nullable()->after('accepted_version_id');
            $table->boolean('starts_within_cooling_off')->nullable()->after('accepted_terms_name');
            $table->date('expiry_noticed_for')->nullable();
            $table->nullableMorphs('origin');
        });

        Schema::table('quote_lines', function (Blueprint $table) {
            $table->boolean('is_provisional')->default(false)->after('is_chosen');
            $table->unsignedInteger('choice_group')->nullable()->after('is_provisional');
            $table->string('location_note', 100)->nullable()->after('details');
        });

        Schema::table('quote_versions', function (Blueprint $table) {
            $table->index(['quote_id', 'number']);
            $table->dropUnique(['quote_id', 'number']);
            $table->timestamp('viewed_at')->nullable()->after('created_by');
            $table->timestamp('reminded_at')->nullable()->after('viewed_at');
        });

        DB::table('quotes')->update(['version' => DB::raw(
            'coalesce((select max(number) from quote_versions where quote_versions.quote_id = quotes.id), 1)'
        )]);
    }

    public function down(): void
    {
        Schema::table('quote_versions', function (Blueprint $table) {
            $table->dropColumn(['viewed_at', 'reminded_at']);
            $table->unique(['quote_id', 'number']);
            $table->dropIndex(['quote_id', 'number']);
        });

        Schema::table('quote_lines', function (Blueprint $table) {
            $table->dropColumn(['is_provisional', 'choice_group', 'location_note']);
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lost_reason_id');
            $table->dropMorphs('origin');
            $table->dropColumn([
                'version', 'is_consumer', 'layout', 'pricing_method', 'start_period', 'duration', 'payment_terms',
                'payment_schedule', 'lost_reason_note', 'accepted_terms_name', 'starts_within_cooling_off',
                'expiry_noticed_for',
            ]);
        });
    }
};

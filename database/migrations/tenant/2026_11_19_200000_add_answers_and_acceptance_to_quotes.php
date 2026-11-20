<?php

use App\Models\ServiceOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A link can be answered once: used_at says when, and the answer stays in the
 * link's own payload, so any record that asks someone without an account for
 * one answer needs nothing more than a purpose.
 *
 * A quote keeps who accepted it, with their signature and whether that happened
 * through the link or in the app, and the werkbon it became. A line the customer
 * may choose remembers whether they chose it: null until anyone decided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_tokens', function (Blueprint $table) {
            $table->timestamp('used_at')->nullable()->after('use_count');
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->boolean('customer_picks_options')->default(true)->after('closing_text');
            $table->string('accepted_name')->nullable()->after('decided_at');
            $table->longText('accepted_signature')->nullable()->after('accepted_name');
            $table->string('accepted_via', 10)->nullable()->after('accepted_signature');
            $table->foreignIdFor(ServiceOrder::class)->nullable()->after('project_id')->constrained()->nullOnDelete();
        });

        Schema::table('quote_lines', function (Blueprint $table) {
            $table->boolean('is_chosen')->nullable()->after('is_optional');
        });
    }

    public function down(): void
    {
        Schema::table('quote_lines', function (Blueprint $table) {
            $table->dropColumn('is_chosen');
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(ServiceOrder::class);
            $table->dropColumn(['customer_picks_options', 'accepted_name', 'accepted_signature', 'accepted_via']);
        });

        Schema::table('access_tokens', function (Blueprint $table) {
            $table->dropColumn('used_at');
        });
    }
};

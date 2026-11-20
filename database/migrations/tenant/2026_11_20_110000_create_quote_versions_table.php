<?php

use App\Models\AccessToken;
use App\Models\Quote;
use App\Models\QuoteVersion;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer was shown, every time: a quote sent becomes a numbered
 * version, frozen as the customer saw it, with the address it went to and the
 * link that showed it. The quote keeps which version was accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Quote::class)->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('snapshot');
            $table->string('recipient')->nullable();
            $table->foreignIdFor(AccessToken::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['quote_id', 'number']);
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignIdFor(QuoteVersion::class, 'accepted_version_id')->nullable()->after('accepted_via')
                ->constrained('quote_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accepted_version_id');
        });

        Schema::dropIfExists('quote_versions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Some systems sign each notice with a secret of their own, handed out once
 * when the webhook is made (Moneybird). It is kept here, encrypted, beside
 * Lavoro's own token in the address.
 *
 * A webhook is also made for one administration, division or company there;
 * the scope says which, so a webhook for another one is made again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_webhooks', function (Blueprint $table) {
            $table->text('signing_secret')->nullable();
            $table->string('scope')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('integration_webhooks', function (Blueprint $table) {
            $table->dropColumn(['signing_secret', 'scope']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two more things a notification can carry, written once like its sentence.
 *
 * The buttons on the banner, each a label and where it leads: a reminder offers
 * the route and the customer's number. And the moment after which pushing it is
 * pointless: a reminder that lands after the appointment began is worse than
 * none, so the push service is told to give up by then.
 *
 * Indexed with the type because the reminder round asks for exactly that, every
 * five minutes: which reminders of a kind are still ahead, and which have passed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->json('actions')->nullable()->after('body');
            $table->timestamp('expires_at')->nullable()->after('actions');
            $table->index(['type', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropIndex(['type', 'expires_at']);
            $table->dropColumn(['actions', 'expires_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who wants to hear about what.
 *
 * A row is an opt-in and nothing more: it grants no sight of anything. Whether
 * the subscription actually delivers is decided twice against the reader's
 * permissions — once when it is set, so nobody is signed up for news they may
 * not read, and again when the fact occurs, so a permission taken away stops the
 * notifications without deleting the wish to have them. Giving the permission
 * back resumes delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /** The signal event key subscribed to, e.g. 'ticket.created'. */
            $table->string('type');

            $table->timestamps();

            $table->unique(['user_id', 'type']);

            /** The delivery side asks "who wants this type", without a user. */
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_subscriptions');
    }
};

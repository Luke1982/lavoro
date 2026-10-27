<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What somebody has been told, and whether they have taken it in.
 *
 * The sentence is frozen at the moment the fact occurs rather than rebuilt when
 * the bell is opened, for the same reason the activity trail copies the actor's
 * name: a storing that is later renamed, closed or deleted must not be able to
 * rewrite what somebody was told last week. The record is kept alongside it, so
 * the notification can still link through to whatever is left of it.
 *
 * read_at is a timestamp and not a flag because acknowledging is reversible: the
 * column goes back to null and the notification is unread again, which a counter
 * or a boolean 'seen' would not survive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /** The signal event key this came from, e.g. 'ticket.created'. */
            $table->string('type');

            /** UserNotificationPriority: 1 laag, 2 normaal, 3 hoog. Ordered on. */
            $table->unsignedTinyInteger('priority')->default(2);

            $table->string('notificationable_type');
            $table->unsignedBigInteger('notificationable_id');
            $table->string('title');
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            /** Every read of this is "mine, unread first, newest first". */
            $table->index(['user_id', 'read_at', 'created_at']);

            /** Named by hand: the generated name is three characters over MySQL's limit. */
            $table->index(
                ['notificationable_type', 'notificationable_id'],
                'user_notifications_notificationable_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One browser that has agreed to be interrupted.
 *
 * Kept apart from device_tokens on purpose. That table holds a single opaque
 * string handed out by Firebase for a native app; a web push subscription is an
 * endpoint at somebody else's push service plus the two keys that message has to
 * be encrypted with, and it dies of old age on its own. Sharing one table would
 * mean every reader of either had to know which kind it was looking at.
 *
 * The endpoint identifies the subscription, so it is unique: a browser that
 * re-subscribes must update its keys rather than collect a second row that no
 * longer decrypts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint', 500)->unique();

            /** The subscription's p256dh key, which the payload is encrypted to. */
            $table->string('public_key');

            /** The subscription's auth secret. */
            $table->string('auth_token');

            /** aes128gcm for anything current; older browsers said aesgcm. */
            $table->string('content_encoding')->default('aes128gcm');

            /** Which browser this is, so a person can tell their devices apart. */
            $table->string('user_agent')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};

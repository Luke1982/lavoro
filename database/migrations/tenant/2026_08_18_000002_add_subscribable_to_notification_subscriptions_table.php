<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A subscription may now point at a single record.
 *
 * Without a record it stays what it was: this kind of news, wherever it comes
 * from. With a record it is about that one thing, and then the kind may stay
 * empty -- that is "tell me everything there is to report about this".
 *
 * The unique key takes both columns in, but does not carry the load alone:
 * MySQL counts NULLs as different from each other, so two identical rows
 * without a record slip past it. The Form Request therefore writes the
 * comparison out with whereNull; this is the safety net, not the rule.
 *
 * The order below is not free. The old key on (user_id, type) is the only index
 * covering user_id, and the foreign key hangs on it: removing it first produces
 * MySQL error 1553. The new key also starts with user_id, so once it is there
 * the old one may go. On SQLite none of this shows, because there it is
 * allowed -- exactly the kind of difference a migration breaks on as soon as it
 * runs somewhere other than in the tests.
 */
return new class extends Migration
{
    /**
     * Named by hand. The name Laravel would invent reads
     * notification_subscriptions_subscribable_type_subscribable_id_index and at
     * 66 characters is two over what MySQL allows for an index name.
     */
    private const SUBSCRIBABLE_INDEX = 'notification_subscriptions_subscribable_index';

    private const COMPOSITE_UNIQUE = 'notification_subscriptions_unique';

    private const ORIGINAL_UNIQUE = 'notification_subscriptions_user_id_type_unique';

    public function up(): void
    {
        Schema::table('notification_subscriptions', function (Blueprint $table) {
            $table->nullableMorphs('subscribable', self::SUBSCRIBABLE_INDEX);
        });

        Schema::table('notification_subscriptions', function (Blueprint $table) {
            $table->string('type')->nullable()->change();
        });

        Schema::table('notification_subscriptions', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'type', 'subscribable_type', 'subscribable_id'],
                self::COMPOSITE_UNIQUE
            );
        });

        Schema::table('notification_subscriptions', function (Blueprint $table) {
            $table->dropUnique(self::ORIGINAL_UNIQUE);
        });
    }

    /**
     * Going back is only possible when nothing is left that could not exist
     * before this migration: a subscription to one record, or a subscription
     * without a kind. Those rows exist by the grace of this migration, so they
     * go with it.
     */
    public function down(): void
    {
        DB::table('notification_subscriptions')
            ->whereNotNull('subscribable_type')
            ->orWhereNull('type')
            ->delete();

        Schema::table('notification_subscriptions', function (Blueprint $table) {
            $table->string('type')->nullable(false)->change();
        });

        Schema::table('notification_subscriptions', function (Blueprint $table) {
            $table->unique(['user_id', 'type'], self::ORIGINAL_UNIQUE);
        });

        Schema::table('notification_subscriptions', function (Blueprint $table) {
            $table->dropUnique(self::COMPOSITE_UNIQUE);
        });

        Schema::table('notification_subscriptions', function (Blueprint $table) {
            $table->dropIndex(self::SUBSCRIBABLE_INDEX);
            $table->dropColumn(['subscribable_type', 'subscribable_id']);
        });
    }
};

<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Managing your own subscriptions needs no permission: what you may read, you
 * may ask to be told about. Signing somebody else up does, because it decides
 * what lands in another person's bell without them asking for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        DB::table('permissions')->insert([
            [
                'name' => 'usernotification.manage_subscriptions',
                'label' => 'Meldingen van anderen beheren',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'usernotification.manage_subscriptions')->delete();
    }
};

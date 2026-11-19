<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SnelStart was the one link with an outside system, spread over two settings,
 * a column on customers and one on materials. This moves all of it onto the
 * integration tables, so it is a connection like any other.
 *
 * The keys become the connection's credentials. The setting "the nightly import
 * also updates existing customers" becomes which side leads when a customer was
 * changed on both sides: off meant Lavoro's version stays, and that is what
 * leading = lavoro does.
 *
 * Every customer made in Lavoro was handed a made-up snelstart_id, so SnelStart
 * would not refuse the werkbon before sending. Those ids are copied across
 * unconfirmed; the first full pull from SnelStart confirms the real ones and
 * drops the rest, which is why a link has a confirmed_at at all.
 *
 * Without keys there is still a connection when materials carry SnelStart ids
 * (only an import gives them one), switched off, so the links survive until
 * someone enters the keys again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $credentials = [
            'client_key' => $this->secret('snelstart_client_key'),
            'subscription_key' => $this->secret('snelstart_subscription_key'),
        ];

        $has_keys = filled($credentials['client_key']) && filled($credentials['subscription_key']);
        $has_ids = DB::table('materials')->whereNotNull('snelstart_id')->exists();

        if ($has_keys || $has_ids) {
            $updates_existing = DB::table('general_settings')
                ->where('key', 'snelstart_nightly_update_customers')
                ->value('value') ?? '1';

            $connection_id = DB::table('integration_connections')->insertGetId([
                'connector' => 'snelstart',
                'credentials' => $has_keys ? Crypt::encryptString(json_encode($credentials)) : null,
                'settings' => json_encode([
                    'customers' => [
                        'read' => true,
                        'write' => false,
                        'leading' => $updates_existing === '1' ? 'external' : 'lavoro',
                    ],
                    'materials' => ['read' => true],
                    'service_orders' => ['form' => 'sales_order'],
                ]),
                'is_active' => $has_keys,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->copyLinks('customers', 'App\\Models\\Customer', $connection_id);
            $this->copyLinks('materials', 'App\\Models\\Material', $connection_id);
        }

        DB::table('general_settings')->whereIn('key', [
            'snelstart_client_key',
            'snelstart_subscription_key',
            'snelstart_nightly_update_customers',
        ])->delete();

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['snelstart_id']);
            $table->dropColumn('snelstart_id');
        });

        Schema::table('materials', function (Blueprint $table) {
            $table->dropUnique(['snelstart_id']);
            $table->dropColumn('snelstart_id');
        });
    }

    /**
     * Gives the columns back, filled from the links. The keys stay in the
     * connection: putting secrets back in general_settings is not worth a
     * rollback that is only ever run on a development machine.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->uuid('snelstart_id')->nullable()->unique();
        });

        Schema::table('materials', function (Blueprint $table) {
            $table->uuid('snelstart_id')->nullable()->unique();
        });

        $connection_id = DB::table('integration_connections')->where('connector', 'snelstart')->value('id');

        if (!$connection_id) {
            return;
        }

        foreach (['customers' => 'App\\Models\\Customer', 'materials' => 'App\\Models\\Material'] as $table => $type) {
            DB::table('integrationables')
                ->where('integration_connection_id', $connection_id)
                ->where('integrationable_type', $type)
                ->orderBy('id')
                ->each(fn ($link) => DB::table($table)
                    ->where('id', $link->integrationable_id)
                    ->update(['snelstart_id' => $link->external_id]));
        }
    }

    private function copyLinks(string $table, string $type, int $connection_id): void
    {
        DB::table($table)
            ->whereNotNull('snelstart_id')
            ->orderBy('id')
            ->select(['id', 'snelstart_id'])
            ->chunk(500, function ($rows) use ($type, $connection_id) {
                DB::table('integrationables')->insert($rows->map(fn ($row) => [
                    'integration_connection_id' => $connection_id,
                    'integrationable_type' => $type,
                    'integrationable_id' => $row->id,
                    'external_id' => $row->snelstart_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            });
    }

    /** A key that no longer deciphers (APP_KEY was changed) reads as not set, as GeneralSetting does. */
    private function secret(string $key): ?string
    {
        $value = DB::table('general_settings')->where('key', $key)->value('value');

        if (!filled($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
};

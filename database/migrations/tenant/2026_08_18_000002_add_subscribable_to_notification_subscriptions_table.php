<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Een abonnement mag voortaan één record aanwijzen.
 *
 * Zonder record blijft het wat het was: dit soort nieuws, waar het ook vandaan
 * komt. Mét record gaat het over dat ene ding, en dan mag het soort leeg blijven
 * — dat is "vertel me alles wat hierover te melden valt".
 *
 * De unieke sleutel neemt de twee kolommen mee, maar draagt de last niet alleen:
 * MySQL rekent NULL's onderling als verschillend, dus twee identieke rijen zonder
 * record glippen erlangs. De Form Request schrijft de vergelijking daarom uit met
 * whereNull erbij; dit is het vangnet, niet de regel.
 *
 * De volgorde hieronder is niet vrij. De oude sleutel op (user_id, type) is de
 * enige index die user_id dekt, en daar hangt de foreign key aan: hem eerst
 * weghalen levert MySQL-fout 1553 op. De nieuwe sleutel begint óók met user_id,
 * dus zodra die er staat mag de oude weg. Op SQLite valt dat niet op, want daar
 * mag het wel — precies het soort verschil waar een migratie op stukloopt zodra
 * hij ergens anders dan in de tests draait.
 */
return new class extends Migration
{
    /**
     * Met de hand benoemd. De naam die Laravel zou verzinnen luidt
     * notification_subscriptions_subscribable_type_subscribable_id_index en is met
     * 66 tekens twee over wat MySQL aan een indexnaam toestaat.
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
     * Terug kan alleen als er niets meer staat dat er vóór deze migratie niet kon
     * staan: een abonnement op één record, of een abonnement zonder soort. Die
     * rijen bestaan bij de gratie van deze migratie, dus die gaan met hem mee.
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

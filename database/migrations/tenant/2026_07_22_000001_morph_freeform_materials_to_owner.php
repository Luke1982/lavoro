<?php

use App\Models\ServiceOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Freeform lines move from belonging to a service order to belonging to anything that
     * carries materials, so a task instance can own them too.
     *
     * The columns are added nullable and filled before they are tightened: morphs() is NOT
     * NULL without a default, which strict mode rejects outright on a populated table. The
     * foreign key has to go before its column, and with it goes the database-level cascade
     * that kept freeform lines from outliving their order — ServiceOrder::deleting sweeps
     * them by morph instead.
     *
     * The default index name for this morph is 70 characters, past MySQL's 64 character
     * limit, hence the explicit one.
     */
    private const INDEX_NAME = 'freeform_materials_freeformmateriable_index';

    public function up(): void
    {
        Schema::table('freeform_materials', function (Blueprint $table) {
            $table->nullableMorphs('freeformmateriable', self::INDEX_NAME);
        });

        DB::table('freeform_materials')->update([
            'freeformmateriable_type' => ServiceOrder::class,
            'freeformmateriable_id' => DB::raw('service_order_id'),
        ]);

        Schema::table('freeform_materials', function (Blueprint $table) {
            $table->string('freeformmateriable_type')->nullable(false)->change();
            $table->unsignedBigInteger('freeformmateriable_id')->nullable(false)->change();
        });

        Schema::table('freeform_materials', function (Blueprint $table) {
            $table->dropForeign(['service_order_id']);
        });

        Schema::table('freeform_materials', function (Blueprint $table) {
            $table->dropColumn('service_order_id');
        });
    }

    /**
     * Lossy by nature: a freeform line owned by a task instance has no service order column
     * to return to, and no such line could exist before this migration ran, so rolling back
     * discards them.
     */
    public function down(): void
    {
        Schema::table('freeform_materials', function (Blueprint $table) {
            $table->unsignedBigInteger('service_order_id')->nullable()->after('id');
        });

        DB::table('freeform_materials')
            ->where('freeformmateriable_type', ServiceOrder::class)
            ->update(['service_order_id' => DB::raw('freeformmateriable_id')]);

        DB::table('freeform_materials')->whereNull('service_order_id')->delete();

        Schema::table('freeform_materials', function (Blueprint $table) {
            $table->unsignedBigInteger('service_order_id')->nullable(false)->change();
        });

        Schema::table('freeform_materials', function (Blueprint $table) {
            $table->foreign('service_order_id')
                ->references('id')
                ->on('service_orders')
                ->cascadeOnDelete();
        });

        Schema::table('freeform_materials', function (Blueprint $table) {
            $table->dropMorphs('freeformmateriable', self::INDEX_NAME);
        });
    }
};

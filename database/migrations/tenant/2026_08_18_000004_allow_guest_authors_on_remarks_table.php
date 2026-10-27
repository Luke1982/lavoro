<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A remark no longer has to belong to a user.
 *
 * A customer explaining something through an upload link has no account and is
 * not given one. The name is copied over and not kept as a reference, like in
 * the timeline: a customer who is renamed later must not retroactively have
 * said something else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remarks', function (Blueprint $table) {
            $table->string('author_name')->nullable()->after('user_id');
        });

        Schema::table('remarks', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    /**
     * Going back is only possible when no remarks from customers are left:
     * those have no user and do not fit the old column.
     *
     * They are not thrown away here. A subscription is a preference you switch
     * on again, but this is what a customer wrote about their own incident, and
     * that does not disappear as a by-catch of a schema change. Whoever wants
     * to go back is told what is in the way.
     */
    public function down(): void
    {
        $from_customers = DB::table('remarks')->whereNull('user_id')->count();

        if ($from_customers > 0) {
            throw new RuntimeException(
                'Er staan ' . $from_customers . ' opmerkingen van klanten zonder gebruiker. '
                . 'Wijs ze toe aan een gebruiker of verwijder ze voordat je deze migratie terugdraait.'
            );
        }

        Schema::table('remarks', function (Blueprint $table) {
            $table->dropColumn('author_name');
        });

        Schema::table('remarks', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};

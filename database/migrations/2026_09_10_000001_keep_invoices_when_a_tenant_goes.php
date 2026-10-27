<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice outlives the customer it was sent to.
 *
 * invoices.tenant_id was a foreign key with cascadeOnDelete, so removing a
 * customer took their invoices with them. Two things break at once: the books
 * have to keep an issued invoice for seven years, and the number it carried came
 * free again -- the next invoice took it, because the next number was the
 * highest one in the table plus one. Two different invoices with the same
 * number is precisely what a continuous series must never produce.
 *
 * So: the invoices stay, with the customer's name written on them, and the
 * numbering runs off a counter that only goes up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('invoices', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->string('tenant_name')->nullable()->after('tenant_id');
        });

        DB::connection('central')->statement(
            'UPDATE invoices JOIN tenants ON tenants.id = invoices.tenant_id SET invoices.tenant_name = tenants.name'
        );

        Schema::connection('central')->create('invoice_numbers', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        /**
         * Start where the existing invoices left off, so the first number after
         * this migration follows the last one that was really issued.
         */
        $issued = DB::connection('central')->table('invoices')->pluck('number');
        $highest = [];

        foreach ($issued as $number) {
            [$year, $sequence] = array_pad(explode('-LVR-', (string) $number), 2, null);

            if (ctype_digit((string) $year) && ctype_digit((string) $sequence)) {
                $highest[(int) $year] = max($highest[(int) $year] ?? 0, (int) $sequence);
            }
        }

        foreach ($highest as $year => $last) {
            DB::connection('central')->table('invoice_numbers')->insert([
                'year' => $year,
                'last_number' => $last,
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('invoice_numbers');

        /**
         * The name stays behind: putting the foreign key back would delete
         * every invoice whose customer is already gone.
         */
        Schema::connection('central')->table('invoices', function (Blueprint $table) {
            $table->dropColumn('tenant_name');
        });
    }
};

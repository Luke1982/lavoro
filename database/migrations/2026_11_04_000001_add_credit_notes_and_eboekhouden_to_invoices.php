<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A credit note that names the invoice it cancels, and the bookkeeping in
 * e-Boekhouden.
 *
 * The credit note an invoice gets when it was wrong has to say which invoice
 * that was: without that reference it is a loose negative amount, and the
 * customer and the tax office cannot tell what it cancels.
 *
 * e-Boekhouden knows the customer by the code of a relation there. That code is
 * linked by hand, after searching, so the same company does not end up in the
 * bookkeeping twice.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('invoices', function (Blueprint $table) {
            $table->foreignId('credits_invoice_id')->nullable()->after('tenant_name')
                ->constrained('invoices');
            $table->timestamp('booked_at')->nullable()->after('collection_batch');
            $table->string('booking_number', 32)->nullable()->after('booked_at');
            $table->string('booking_error')->nullable()->after('booking_number');
        });

        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->string('eboekhouden_relation_code', 32)->nullable()->after('coc_number');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->dropColumn('eboekhouden_relation_code');
        });

        Schema::connection('central')->table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credits_invoice_id');
            $table->dropColumn(['booked_at', 'booking_number', 'booking_error']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->string('payment_method')->default('transfer')->after('billing_period');
            $table->string('iban', 34)->nullable()->after('payment_method');
            $table->string('bic', 11)->nullable()->after('iban');
            $table->string('account_holder')->nullable()->after('bic');
            $table->string('mandate_reference', 35)->nullable()->after('account_holder');
            $table->date('mandate_signed_on')->nullable()->after('mandate_reference');
        });

        Schema::connection('central')->table('invoices', function (Blueprint $table) {
            /** Wanneer de factuur in een incassobestand is meegegaan. */
            $table->timestamp('collected_at')->nullable()->after('mail_error');
            $table->string('collection_batch')->nullable()->after('collected_at');
            $table->index('collection_batch');
        });

        foreach ([
            'bic' => 'ASNBNL21',
            'incassant_id' => '',
            'website' => 'majorlabel.nl',
        ] as $key => $value) {
            DB::connection('central')->table('issuer_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method', 'iban', 'bic', 'account_holder',
                'mandate_reference', 'mandate_signed_on',
            ]);
        });

        Schema::connection('central')->table('invoices', function (Blueprint $table) {
            $table->dropIndex(['collection_batch']);
            $table->dropColumn(['collected_at', 'collection_batch']);
        });
    }
};

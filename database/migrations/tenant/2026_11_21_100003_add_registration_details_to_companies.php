<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a quote has to say about who makes it: the KvK number is required by law
 * on every quote, and the VAT number, the bank account and the ways to reach the
 * company belong beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('coc_number', 20)->nullable()->after('country');
            $table->string('vat_number', 20)->nullable()->after('coc_number');
            $table->string('iban', 34)->nullable()->after('vat_number');
            $table->string('email')->nullable()->after('iban');
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('website')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['coc_number', 'vat_number', 'iban', 'email', 'phone', 'website']);
        });
    }
};

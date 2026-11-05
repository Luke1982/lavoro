<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice is credited once. The lock in Invoicer::credit makes sure of it;
 * this makes sure of it when something goes around that.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('invoices', function (Blueprint $table) {
            $table->unique('credits_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('invoices', function (Blueprint $table) {
            $table->dropUnique(['credits_invoice_id']);
        });
    }
};

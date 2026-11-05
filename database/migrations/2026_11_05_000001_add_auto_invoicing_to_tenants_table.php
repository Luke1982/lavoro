<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the hourly round may create this customer's invoices by itself.
 * On for everyone, as before; off means only the button on the invoice page.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->boolean('auto_invoicing')->default(true)->after('billing_period_started_on');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenants', fn (Blueprint $table) => $table->dropColumn('auto_invoicing'));
    }
};

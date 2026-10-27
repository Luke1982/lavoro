<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Amounts on an invoice may be negative.
 *
 * They were unsigned in the database, so a credit note did not fit. If a
 * customer cancels halfway through a month already paid for, they are owed
 * money; without a credit note that credit sat waiting for a next invoice that,
 * for a departed customer, never comes.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        foreach (['subtotal_cents', 'total_cents', 'vat_cents', 'gross_cents'] as $column) {
            DB::connection('central')->statement("ALTER TABLE invoices MODIFY `{$column}` INT NOT NULL DEFAULT 0");
        }
    }

    public function down(): void
    {
        foreach (['subtotal_cents', 'total_cents', 'vat_cents', 'gross_cents'] as $column) {
            DB::connection('central')->statement("ALTER TABLE invoices MODIFY `{$column}` INT UNSIGNED NOT NULL DEFAULT 0");
        }
    }
};

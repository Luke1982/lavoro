<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bedragen op een factuur mogen negatief zijn.
 *
 * Ze stonden als unsigned in de database, dus een creditfactuur kon er niet in.
 * Zegt een klant halverwege een al betaalde maand op, dan heeft hij geld
 * tegoed; zonder creditfactuur bleef dat tegoed staan wachten op een volgende
 * factuur die er voor een vertrokken klant nooit meer komt.
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

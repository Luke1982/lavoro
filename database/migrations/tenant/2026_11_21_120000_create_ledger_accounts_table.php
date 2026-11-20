<?php

use App\Models\LedgerAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The revenue accounts of the bookkeeping, made here or fetched from a linked
 * system, and the one a material or product is booked on.
 *
 * The code is the number a bookkeeper knows the account by ("8000"). It may be
 * missing: Moneybird does not require one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->nullable()->unique();
            $table->string('name');
            $table->timestamps();
        });

        foreach (['materials', 'products'] as $table_name) {
            Schema::table($table_name, function (Blueprint $table) {
                $table->foreignIdFor(LedgerAccount::class)->nullable()->constrained()->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['materials', 'products'] as $table_name) {
            Schema::table($table_name, function (Blueprint $table) {
                $table->dropConstrainedForeignIdFor(LedgerAccount::class);
            });
        }

        Schema::dropIfExists('ledger_accounts');
    }
};

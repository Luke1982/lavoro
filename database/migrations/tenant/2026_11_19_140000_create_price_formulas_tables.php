<?php

use App\Models\PriceFormula;
use App\Models\PriceFormulaVersion;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Price formulas: node graphs that work out what a maintenance contract costs
 * from the machines on it. A formula keeps every version a contract has used,
 * because a contract stays on its version until somebody moves it on; the
 * restrict on the contract's version is what keeps such a version from going.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_formulas', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('price_formula_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(PriceFormula::class)->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('graph');
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['price_formula_id', 'number']);
        });

        Schema::table('maintenance_contracts', function (Blueprint $table) {
            $table->decimal('one_time_price', 10, 2)->nullable()->after('price_interval_days');
            $table->foreignIdFor(PriceFormulaVersion::class)->nullable()->after('one_time_price')
                ->constrained()->restrictOnDelete();
            $table->json('price_formula_inputs')->nullable()->after('price_formula_version_id');
        });

        Schema::table('maintenance_contract_templates', function (Blueprint $table) {
            $table->foreignIdFor(PriceFormula::class)->nullable()->after('price_interval_days')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_contract_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(PriceFormula::class);
        });

        Schema::table('maintenance_contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(PriceFormulaVersion::class);
            $table->dropColumn(['one_time_price', 'price_formula_inputs']);
        });

        Schema::dropIfExists('price_formula_versions');
        Schema::dropIfExists('price_formulas');
    }
};

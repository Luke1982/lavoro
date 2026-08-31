<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->date('subscription_started_on')->nullable()->after('package_key');
            $table->string('billing_period')->default('monthly')->after('subscription_started_on');
        });

        /**
         * Losse posten die op de eerstvolgende factuur horen: bijgekochte AI en
         * verrekeningen van een pakketwissel halverwege een periode. Ze staan
         * los van het abonnement omdat ze eenmalig zijn en niet meelopen in de
         * maandprijs.
         */
        Schema::connection('central')->create('pending_charges', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('description');
            $table->integer('amount_cents');
            $table->foreignId('invoice_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'invoice_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::connection('central')->create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('tenant_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('issued_on');
            $table->unsignedInteger('subtotal_cents');
            $table->integer('discount_cents')->default(0);
            $table->unsignedInteger('total_cents');
            $table->timestamps();

            $table->index('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::connection('central')->create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->integer('amount_cents');
            $table->timestamps();
        });

        DB::connection('central')->table('pricing_settings')->updateOrInsert(
            ['key' => 'yearly_discount_percent'],
            ['value' => 2, 'created_at' => now(), 'updated_at' => now()],
        );

        DB::connection('central')->table('tenants')->whereNull('subscription_started_on')
            ->update(['subscription_started_on' => now()->startOfMonth()->toDateString()]);
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('invoice_lines');
        Schema::connection('central')->dropIfExists('invoices');
        Schema::connection('central')->dropIfExists('pending_charges');
        Schema::connection('central')->table('tenants', fn (Blueprint $t) => $t->dropColumn(['subscription_started_on', 'billing_period']));
    }
};

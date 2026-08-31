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
            $table->string('invoice_email')->nullable()->after('billing_period');
            $table->string('invoice_address')->nullable()->after('invoice_email');
            $table->string('invoice_postcode', 16)->nullable()->after('invoice_address');
            $table->string('invoice_city')->nullable()->after('invoice_postcode');
            $table->string('invoice_country', 2)->default('NL')->after('invoice_city');
            $table->string('vat_number', 32)->nullable()->after('invoice_country');
            $table->string('coc_number', 32)->nullable()->after('vat_number');
        });

        Schema::connection('central')->table('invoices', function (Blueprint $table) {
            $table->unsignedSmallInteger('vat_percent')->default(21)->after('total_cents');
            $table->unsignedInteger('vat_cents')->default(0)->after('vat_percent');
            $table->unsignedInteger('gross_cents')->default(0)->after('vat_cents');
            $table->date('due_on')->nullable()->after('issued_on');
        });

        /** Onze eigen gegevens; ze horen op elke factuur en in de XML. */
        Schema::connection('central')->create('issuer_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        $now = now();

        foreach ([
            'name' => 'Major Label', 'address' => '', 'postcode' => '', 'city' => '',
            'country' => 'NL', 'vat_number' => '', 'coc_number' => '', 'iban' => '',
            'email' => 'info@majorlabel.nl', 'payment_days' => '14',
        ] as $key => $value) {
            DB::connection('central')->table('issuer_settings')->insert([
                'key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('issuer_settings');
        Schema::connection('central')->table('invoices', fn (Blueprint $t) => $t->dropColumn(['vat_percent', 'vat_cents', 'gross_cents', 'due_on']));
        Schema::connection('central')->table('tenants', fn (Blueprint $t) => $t->dropColumn([
            'invoice_email', 'invoice_address', 'invoice_postcode', 'invoice_city', 'invoice_country', 'vat_number', 'coc_number',
        ]));
    }
};

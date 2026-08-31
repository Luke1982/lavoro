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
        Schema::connection('central')->create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->unsignedInteger('field_seats');
            $table->unsignedInteger('office_seats');
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('extra_field_cents');
            $table->unsignedInteger('extra_office_cents');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::connection('central')->create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::connection('central')->create('module_bundles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('module_keys');
            $table->unsignedInteger('price_cents');
            $table->timestamps();
        });

        Schema::connection('central')->create('pricing_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedInteger('value');
            $table->timestamps();
        });

        $now = now();

        DB::connection('central')->table('packages')->insert([
            ['key' => 'starter', 'name' => 'Starter', 'field_seats' => 1, 'office_seats' => 1, 'price_cents' => 2750, 'extra_field_cents' => 1200, 'extra_office_cents' => 800, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'team', 'name' => 'Team', 'field_seats' => 5, 'office_seats' => 2, 'price_cents' => 8750, 'extra_field_cents' => 1100, 'extra_office_cents' => 750, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'business', 'name' => 'Business', 'field_seats' => 10, 'office_seats' => 4, 'price_cents' => 16000, 'extra_field_cents' => 1000, 'extra_office_cents' => 700, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'enterprise', 'name' => 'Enterprise', 'field_seats' => 15, 'office_seats' => 6, 'price_cents' => 23000, 'extra_field_cents' => 950, 'extra_office_cents' => 650, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::connection('central')->table('modules')->insert([
            ['key' => 'quotes', 'name' => 'Offertes', 'price_cents' => 2750, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'invoices', 'name' => 'Facturen', 'price_cents' => 2750, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'snelstart', 'name' => 'SnelStart', 'price_cents' => 0, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'google_calendar', 'name' => 'Google Agenda', 'price_cents' => 0, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'location_tracking', 'name' => 'Locatie volgen', 'price_cents' => 0, 'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'assistant', 'name' => 'AI-assistent', 'price_cents' => 2250, 'sort_order' => 6, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::connection('central')->table('module_bundles')->insert([
            ['name' => 'Offertes + Facturen', 'module_keys' => json_encode(['quotes', 'invoices']), 'price_cents' => 4000, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::connection('central')->table('pricing_settings')->insert([
            ['key' => 'included_storage_gb', 'value' => 50, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'storage_extra_per_gb_cents', 'value' => 50, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'ai_allowance_micros', 'value' => 12_500_000, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('pricing_settings');
        Schema::connection('central')->dropIfExists('module_bundles');
        Schema::connection('central')->dropIfExists('modules');
        Schema::connection('central')->dropIfExists('packages');
    }
};

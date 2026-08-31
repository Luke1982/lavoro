<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Het verbruik van de assistent verhuist naar de centrale database. Daar
     * wordt het tegoed aan afgemeten en telt het beheer het over alle klanten
     * op; uit een tabel per klant kan dat allebei niet. De klant zelf komt er
     * niet meer bij, en dat hoort ook niet: het is een factuurpost.
     */
    public function up(): void
    {
        if (!Schema::hasTable('assistant_usage')) {
            return;
        }

        $tenant_id = (string) tenancy()->tenant->getTenantKey();

        DB::table('assistant_usage')->orderBy('id')->chunk(500, function ($rows) use ($tenant_id) {
            DB::connection('central')->table('assistant_usage')->insert(
                $rows->map(fn ($row) => [
                    'tenant_id' => $tenant_id,
                    'user_id' => $row->user_id,
                    'model' => $row->model,
                    'input_tokens' => $row->input_tokens,
                    'output_tokens' => $row->output_tokens,
                    'cache_write_tokens' => $row->cache_write_tokens,
                    'cache_read_tokens' => $row->cache_read_tokens,
                    'cost_micros' => $row->cost_micros,
                    'cost_usd_micros' => $row->cost_usd_micros,
                    'eur_per_usd' => $row->eur_per_usd,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ])->all()
            );
        });

        Schema::drop('assistant_usage');
    }

    /**
     * Terugdraaien zet de tabel terug maar niet de rijen. Ze staan centraal en
     * horen daar; ze hier weghalen zou het tegoed van de klant wissen.
     */
    public function down(): void
    {
        if (Schema::hasTable('assistant_usage')) {
            return;
        }

        Schema::create('assistant_usage', function ($table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('model');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->unsignedBigInteger('cost_usd_micros')->default(0);
            $table->decimal('eur_per_usd', 10, 6);
            $table->timestamps();
        });
    }
};

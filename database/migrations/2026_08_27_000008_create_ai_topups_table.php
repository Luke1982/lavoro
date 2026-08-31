<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    /**
     * Een eenmalige bijkoop. Niet aan een maand gebonden: wat er niet op gaat
     * blijft staan. Daarom apart van tenants.ai_allowance_micros, dat elke maand
     * opnieuw begint.
     */
    public function up(): void
    {
        Schema::connection('central')->create('ai_topups', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedInteger('paid_cents');
            $table->unsignedBigInteger('granted_micros');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        DB::connection('central')->table('pricing_settings')->updateOrInsert(
            ['key' => 'ai_allowance_micros'],
            ['value' => 22_500_000, 'updated_at' => now(), 'created_at' => now()],
        );

        DB::connection('central')->table('pricing_settings')->updateOrInsert(
            ['key' => 'ai_topup_cents_per_euro_granted'],
            ['value' => 200, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('ai_topups');
    }
};

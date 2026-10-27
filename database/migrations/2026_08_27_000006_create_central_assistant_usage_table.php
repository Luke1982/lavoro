<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    /**
     * The tenant version has a foreign key to users; that cannot be here,
     * because users lives in another database. user_id is therefore a bare
     * column and means something only together with tenant_id.
     */
    public function up(): void
    {
        Schema::connection('central')->create('assistant_usage', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('model');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->unsignedBigInteger('cost_usd_micros')->default(0);
            $table->decimal('eur_per_usd', 10, 6);
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('assistant_usage');
    }
};

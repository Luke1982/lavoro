<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('package_key')->nullable();
            $table->unsignedInteger('extra_field_seats')->default(0);
            $table->unsignedInteger('extra_office_seats')->default(0);
            $table->json('modules')->nullable();
            $table->unsignedInteger('price_override_cents')->nullable();
            $table->unsignedInteger('storage_limit_gb')->default(50);
            $table->string('tenancy_db_username')->nullable();
            $table->text('tenancy_db_password')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenants');
    }
};

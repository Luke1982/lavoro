<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('resellers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->unsignedTinyInteger('commission_percent')->default(10);
            $table->timestamps();
        });

        Schema::connection('central')->create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('reseller_id')->constrained('resellers')->cascadeOnDelete();
            $table->unsignedTinyInteger('discount_percent')->default(10);
            $table->unsignedSmallInteger('discount_months')->default(12);

            /**
             * Eén keer te gebruiken: zodra hier een tenant staat is de bon op.
             * Dat staat op de bon zelf en niet op de tenant, zodat twee klanten
             * dezelfde code niet allebei kunnen verzilveren.
             */
            $table->string('redeemed_by_tenant_id')->nullable()->unique();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->unsignedTinyInteger('coupon_discount_percent')->nullable()->after('discount_percent');
            $table->date('coupon_discount_until')->nullable()->after('coupon_discount_percent');
            $table->unsignedBigInteger('reseller_id')->nullable()->after('coupon_discount_until');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenants', fn (Blueprint $t) => $t->dropColumn(['coupon_discount_percent', 'coupon_discount_until', 'reseller_id']));
        Schema::connection('central')->dropIfExists('coupons');
        Schema::connection('central')->dropIfExists('resellers');
    }
};

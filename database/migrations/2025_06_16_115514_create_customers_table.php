<?php

use App\Models\Customer;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('snelstart_id')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('invoice_email')->nullable();
            $table->string('quotes_email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('website')->nullable();
            $table->string('address')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('postal_address')->nullable();
            $table->string('postal_postal_code')->nullable();
            $table->string('postal_city')->nullable();
            $table->string('postal_country')->nullable();
            $table->string('iban')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('chamber_of_commerce_number')->nullable();
            $table->string('contactname')->nullable();
            $table->string('location_code')->nullable();
            $table->foreignIdFor(Customer::class, 'billing_customer_id')
                ->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('invoices', function (Blueprint $table) {
            $table->timestamp('mailed_at')->nullable()->after('gross_cents');
            $table->string('mail_error')->nullable()->after('mailed_at');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('invoices', function (Blueprint $table) {
            $table->dropColumn(['mailed_at', 'mail_error']);
        });
    }
};

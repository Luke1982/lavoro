<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_order_task_instances', function (Blueprint $table) {
            $table->string('signed_by')->nullable()->after('is_complete');
            $table->mediumText('signature_base64')->nullable()->after('signed_by');
            $table->timestamp('signed_at')->nullable()->after('signature_base64');
        });
    }

    public function down(): void
    {
        Schema::table('service_order_task_instances', function (Blueprint $table) {
            $table->dropColumn(['signed_by', 'signature_base64', 'signed_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $has_closed_sos = DB::table('service_orders')->where('status', 'closed')->exists();
        $closed_stage_id = DB::table('service_order_stages')->where('is_closed_state', true)->value('id');

        if ($has_closed_sos && !$closed_stage_id) {
            $max_order = (int) (DB::table('service_order_stages')->max('order') ?? 0);
            $closed_stage_id = DB::table('service_order_stages')->insertGetId([
                'name'            => 'Afgerond',
                'order'           => $max_order + 1,
                'is_closed_state' => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        if ($has_closed_sos && $closed_stage_id) {
            DB::table('service_orders')
                ->where('status', 'closed')
                ->update(['service_order_stage_id' => $closed_stage_id]);
        }

        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->string('status')->default('open')->nullable()->after('sent_to_customer');
        });

        DB::table('service_orders')
            ->whereNotNull('closed_on')
            ->update(['status' => 'closed']);
    }
};

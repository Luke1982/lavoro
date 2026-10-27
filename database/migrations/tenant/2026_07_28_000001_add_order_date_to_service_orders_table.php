<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Until now the moment of entry stood in for the date the work was ordered, which is
     * what the customer sees on the mail and what the list is read by. Existing orders are
     * backfilled from it so the new field starts out saying what everyone already assumed.
     *
     * Timestamps are stored in UTC while the app is read in Amsterdam time, so the date is
     * taken after converting: an order entered just after midnight would otherwise be
     * backfilled to the day before the one it has always displayed.
     */
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->date('order_date')->nullable();
        });

        $timezone = config('app.display_timezone');

        DB::table('service_orders')
            ->select('id', 'created_at')
            ->whereNotNull('created_at')
            ->orderBy('id')
            ->chunk(1000, function ($rows) use ($timezone) {
                $rows->groupBy(
                    fn ($row) => Carbon::parse($row->created_at)->setTimezone($timezone)->toDateString()
                )->each(
                    fn ($group, $date) => DB::table('service_orders')
                        ->whereIn('id', $group->pluck('id'))
                        ->update(['order_date' => $date])
                );
            });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn('order_date');
        });
    }
};

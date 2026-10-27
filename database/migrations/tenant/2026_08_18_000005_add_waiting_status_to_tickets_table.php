<?php

use App\Enums\TicketStatusses;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The status column does not know the new stage yet.
 *
 * On MySQL tickets.status is an ENUM holding the three old values. Adding a
 * stage to TicketStatusses is therefore not enough: MySQL truncates whatever is
 * not in the enumeration and gives "Data truncated for column 'status'".
 * SQLite, which the tests run on, lets every value through -- so this is
 * precisely the kind of difference that only surfaces on a real database.
 *
 * The values come from the enum itself and are not written out here again:
 * those two lists would drift apart at the next stage added.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->redefineStatus(array_column(TicketStatusses::cases(), 'value'));
    }

    /**
     * Going back is only possible when no incident is on the new stage any
     * more: that value does not fit the old enumeration. They go to
     * 'In behandeling', because that is what waiting for the customer was
     * before it had a stage of its own.
     */
    public function down(): void
    {
        DB::table('tickets')
            ->where('status', TicketStatusses::wacht_op_klant->value)
            ->update(['status' => TicketStatusses::in_behandeling->value]);

        $this->redefineStatus([
            TicketStatusses::open->value,
            TicketStatusses::in_behandeling->value,
            TicketStatusses::gesloten->value,
        ]);
    }

    /** @param  array<int, string>  $values */
    private function redefineStatus(array $values): void
    {
        if (DB::getDriverName() === 'mysql') {
            $list = implode(', ', array_map(fn (string $value) => "'" . $value . "'", $values));

            DB::statement(
                'ALTER TABLE `tickets` MODIFY `status` ENUM(' . $list . ") NOT NULL DEFAULT 'Open'"
            );

            return;
        }

        Schema::table('tickets', function (Blueprint $table) use ($values) {
            $table->enum('status', $values)->default(TicketStatusses::open->value)->change();
        });
    }
};

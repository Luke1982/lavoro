<?php

use App\Enums\TicketStatusses;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De statuskolom kent de nieuwe fase nog niet.
 *
 * tickets.status is op MySQL een ENUM met de drie oude waarden erin. Een fase
 * toevoegen aan TicketStatusses is daarmee niet genoeg: MySQL kapt af wat er niet
 * in de opsomming staat en geeft "Data truncated for column 'status'". SQLite,
 * waar de tests op draaien, laat elke waarde door — dus dit is precies het soort
 * verschil dat pas op een echte database boven water komt.
 *
 * De waarden komen uit de enum zelf en staan hier niet nog een keer uitgeschreven:
 * die twee lijsten zouden uit elkaar lopen bij de volgende fase die erbij komt.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->redefineStatus(array_column(TicketStatusses::cases(), 'value'));
    }

    /**
     * Terug kan alleen als er geen storing meer op de nieuwe fase staat: die waarde
     * past niet in de oude opsomming. Ze gaan naar 'In behandeling', want dat is
     * wat wachten op de klant was voordat het een eigen fase had.
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

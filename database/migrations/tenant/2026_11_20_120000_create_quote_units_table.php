<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The units a quote line is offered in, kept by the company itself under the
 * quote settings. It starts with the ones every installer uses; the usage
 * units of the materials come along on a quote by themselves.
 */
return new class extends Migration
{
    private const STARTING_UNITS = ['stuk', 'uur', 'dag', 'meter', 'm²', 'set', 'km'];

    public function up(): void
    {
        Schema::create('quote_units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30)->unique();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('quote_units')->insert(array_map(
            fn (string $name, int $index) => ['name' => $name, 'position' => $index + 1, 'created_at' => $now, 'updated_at' => $now],
            self::STARTING_UNITS,
            array_keys(self::STARTING_UNITS),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_units');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Why a quote was lost, kept by the company itself under the quote settings, so
 * the lost ones can be counted by reason. A customer who declines on their page
 * picks from the ones meant for them; the rest are for colleagues.
 */
return new class extends Migration
{
    /** @var array<string, bool> each reason, and whether the customer may pick it */
    private const STARTING_REASONS = [
        'Te duur' => true,
        'Gekozen voor een andere aanbieder' => true,
        'Gaat niet door' => true,
        'Geen reactie' => false,
        'Herroepen binnen de bedenktijd' => false,
    ];

    public function up(): void
    {
        Schema::create('quote_lost_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('customer_can_pick')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        $now = now();
        $position = 0;

        foreach (self::STARTING_REASONS as $name => $customer_can_pick) {
            DB::table('quote_lost_reasons')->insert([
                'name' => $name,
                'customer_can_pick' => $customer_can_pick,
                'position' => ++$position,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_lost_reasons');
    }
};

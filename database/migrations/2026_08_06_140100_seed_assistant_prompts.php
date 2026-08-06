<?php

use App\Models\AssistantPrompt;
use Illuminate\Database\Migrations\Migration;

/**
 * The questions this assistant turned out to be good at, put where they belong.
 *
 * Drawn from what it was actually asked while being built: reading a
 * typeplaatje off a photo, finding the machines somebody booked as "onbekend"
 * to keep a job moving, working out when three people are free together. None
 * of that is guessable from a blank prompt box.
 *
 * Shipped ones have no owner. Deleting one is a per-user thing, so these are
 * seeded rather than hard-coded — somebody who never wants to see a question
 * again should be able to say so.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->questions() as $sort => [$context, $label, $question]) {
            AssistantPrompt::firstOrCreate(
                ['user_id' => null, 'label' => $label, 'context' => $context],
                ['question' => $question, 'sort' => $sort],
            );
        }
    }

    public function down(): void
    {
        AssistantPrompt::whereNull('user_id')->delete();
    }

    /** @return array<int, array{0: ?string, 1: string, 2: string}> */
    private function questions(): array
    {
        return [
            ['serviceorders.show', 'Foto\'s uitlezen', 'Kijk naar de foto\'s bij deze werkbon. Dat zijn de '
                . 'machines van deze klant. Zoek uit wat het is, kijk of we het product al hebben, en zeg '
                . 'per onderdeel hoe zeker je bent.'],
            ['serviceorders.show', 'Tijdelijke machines aanvullen', 'Welke machines op deze werkbon staan '
                . 'nog als "onbekend" of zonder serienummer? Kijk of de foto\'s bij deze werkbon genoeg '
                . 'laten zien om ze aan te vullen.'],
            ['serviceorders.show', 'Wie deed wat', 'Wie heeft er wat gedaan aan deze werkbon, en wanneer?'],
            ['serviceorders.show', 'Wanneer gepland', 'Wanneer staat het werk van deze werkbon gepland, '
                . 'en met wie?'],

            ['serviceorders.index', 'Wat staat er open', 'Welke werkbonnen staan er open, en welke daarvan '
                . 'hebben nog geen afspraak?'],

            ['assets.show', 'Typeplaatje uitlezen', 'Kijk naar de foto\'s bij deze machine en lees het '
                . 'typeplaatje af: merk, model en serienummer, met per onderdeel hoe zeker je bent.'],
            ['assets.show', 'Geschiedenis', 'Wat is er met deze machine gebeurd, en wanneer is er voor het '
                . 'laatst onderhoud geweest?'],

            ['customers.show', 'Overzicht', 'Geef een overzicht van deze klant: openstaande werkbonnen, '
                . 'machines en storingen.'],
            ['customers.show', 'Onderhoud op komst', 'Welke machines van deze klant moeten er de komende '
                . 'maand onderhouden worden?'],

            ['products.index', 'Product van een foto', 'Ik heb een foto van een apparaat. Zoek uit wat het '
                . 'is, kijk of we het al in het assortiment hebben, en maak het anders aan.'],
            ['products.index', 'Tijdelijke producten', 'Welke producten of machines staan er nog als '
                . '"onbekend" in het systeem?'],

            ['tickets.show', 'Uitzoeken', 'Wat is er met deze storing aan de hand, en is dit eerder '
                . 'gebeurd bij deze machine?'],

            ['planner.index', 'Wie kan er samen', 'Ik heb een klus van een hele dag met twee man. Wanneer '
                . 'kunnen er twee monteurs tegelijk?'],

            [null, 'Wat kun je?', 'Wat kun je allemaal voor me opzoeken of vastleggen?'],
        ];
    }
};

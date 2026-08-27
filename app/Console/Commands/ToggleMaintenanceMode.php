<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Throwable;

/**
 * Eén commando voor beide kanten. Staat Lavoro aan, dan gaat het eruit; staat het
 * al in onderhoud, dan komt het er weer in. Geef je --message of --until mee, dan
 * is dat altijd een opdracht om in onderhoud te gaan of de pagina bij te werken —
 * anders zou je met een bericht in de hand de site per ongeluk weer openzetten.
 *
 * De onderhoudspagina wordt door `down --render` vooraf getekend en als tekst in
 * het down-bestand bewaard, zodat public/index.php hem kan uitserveren voordat
 * Composer geladen is. Daar komt de enige verrassing in dit bestand vandaan:
 * DownCommand geeft de view niets anders mee dan retryAfter, dus het bericht en
 * het tijdstip moeten via View::share. Dat werkt omdat het tekenen in ditzelfde
 * proces gebeurt, en alleen daarom.
 */
class ToggleMaintenanceMode extends Command
{
    protected $signature = 'app:maintenance
        {--message= : Extra regel op de onderhoudspagina}
        {--until= : Verwachte eindtijd, bijvoorbeeld "16:00"}';

    protected $description = 'Zet Lavoro in onderhoud, of haalt het er weer uit.';

    public function handle(): int
    {
        $wants_down = $this->option('message') !== null || $this->option('until') !== null;

        if (!$wants_down && $this->laravel->maintenanceMode()->active()) {
            return $this->call('up');
        }

        return $this->down();
    }

    private function down(): int
    {
        $until_option = $this->option('until');
        $until = null;

        if ($until_option) {
            $until = $this->parseUntil($until_option);

            if (!$until) {
                $this->error('Kon "' . $until_option . '" niet als tijdstip lezen. Probeer bijvoorbeeld "16:00" of "2026-08-27 16:00".');

                return self::FAILURE;
            }

            if ($until->isPast()) {
                $this->error('"' . $until_option . '" ligt in het verleden. Geef een tijdstip dat nog moet komen.');

                return self::FAILURE;
            }
        }

        $previous = $this->activePayload();

        View::share('maintenance_message', $this->option('message'));
        View::share('maintenance_until', $until ? $this->untilLabel($until) : null);

        $options = [
            '--render' => 'errors::503',
            '--secret' => $previous['secret'] ?? Str::random(32),
        ];

        if ($until) {
            $options['--retry'] = $this->retrySeconds($until);
        }

        $status = $this->call('down', $options);

        if ($status !== self::SUCCESS) {
            return $status;
        }

        if (!$until && !empty($previous['retry'])) {
            $this->warn('De eerder ingestelde eindtijd is vervallen. Geef --until opnieuw mee als die moet blijven staan.');
        }

        $this->newLine();
        $this->line('Die link zet een cookie en laat jou wel door — de rest ziet de onderhoudspagina.');
        $this->line('Weer online met: php artisan app:maintenance');

        return self::SUCCESS;
    }

    /**
     * "16:00" leest Carbon als vandaag om vier uur. Ligt dat al achter ons, dan is
     * de volgende bedoeld — dat is wat je bedoelt als je alleen een klokstand
     * opgeeft.
     *
     * Een dag opschuiven redt een volledige datum uit het verleden niet, en dat is
     * de bedoeling: wie "2026-08-20 09:00" typt heeft zich vergist en hoort dat te
     * horen, niet stilletjes een dag verzet te krijgen. De aanroeper toetst daarom
     * nogmaals op isPast().
     */
    private function parseUntil(string $value): ?Carbon
    {
        try {
            $until = Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }

        return $until->isPast() ? $until->addDay() : $until;
    }

    /**
     * De ondergrens van een minuut is er omdat een venster dat over tien seconden
     * afloopt niets meer zegt: tegen de tijd dat iets de header leest is het moment
     * voorbij. "Kom over een minuut terug" is dan bruikbaarder dan een getal dat al
     * verlopen is.
     */
    private function retrySeconds(Carbon $until): int
    {
        return max(60, $until->getTimestamp() - now()->getTimestamp());
    }

    /**
     * Alleen een klokstand is genoeg zolang het vandaag is; daarbuiten zegt "09:00"
     * op de onderhoudspagina iets wat niet klopt.
     */
    private function untilLabel(Carbon $until): string
    {
        return match (true) {
            $until->isToday() => $until->format('H:i'),
            $until->isTomorrow() => 'morgen ' . $until->format('H:i'),
            default => $until->format('d-m-Y H:i'),
        };
    }

    /**
     * Wat er al stond, als er al iets stond. Twee dingen komen hieruit: het geheim,
     * dat blijft staan omdat een nieuw geheim het cookie ongeldig maakt van iedereen
     * die al binnen was; en de vorige eindtijd, alleen om te kunnen zeggen dat die
     * vervalt als je hem nu niet opnieuw meegeeft.
     *
     * Via het contract en niet via het down-bestand: welk bestand dat is, en of het
     * er überhaupt een is, hangt af van de ingestelde maintenance driver.
     */
    private function activePayload(): array
    {
        $maintenance = $this->laravel->maintenanceMode();

        return $maintenance->active() ? $maintenance->data() : [];
    }
}

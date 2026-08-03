<?php

namespace App\Console\Commands;

use App\Services\GeocodeBackfill;
use Illuminate\Console\Command;

/**
 * Het bijvullen van coördinaten met de hand, voor wie niet wil wachten tot het
 * dashboard er zelf om vraagt.
 *
 * Het werk zelf staat in GeocodeBackfill, want de wachtrij doet precies
 * hetzelfde en die heeft geen console om in te praten.
 */
class GeocodeAddresses extends Command
{
    protected $signature = 'geocode:addresses
        {--limit=100 : Hoeveel adressen dit rondje maximaal opgezocht worden}
        {--days=60 : Hoe ver vooruit en terug er naar afspraken gekeken wordt}
        {--all : Alle klanten zonder coördinaten, niet alleen die met afspraken}';

    protected $description = 'Zoekt ontbrekende coördinaten op voor locaties, klanten en de adressen van afspraken';

    public function handle(GeocodeBackfill $backfill): int
    {
        $result = $backfill->run(
            budget: (int) $this->option('limit'),
            days: (int) $this->option('days'),
            all_customers: (bool) $this->option('all'),
            report: fn (string $kind, string $line) => $this->line(match ($kind) {
                'ok' => '  ✓ ' . $line,
                'gemist' => '  – niet gevonden: ' . $line,
                default => $line,
            }),
        );

        $this->newLine();
        $this->info(
            $result['gevonden'] . ' gevonden, ' . $result['gemist'] . ' niet gevonden. '
            . ($result['resterend'] > 0
                ? 'Alles binnen bereik is opgezocht.'
                : 'Plafond bereikt — draai nog een rondje voor de rest.')
        );

        return self::SUCCESS;
    }
}

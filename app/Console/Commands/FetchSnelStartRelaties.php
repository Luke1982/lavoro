<?php

namespace App\Console\Commands;

use App\Services\SnelStartImport;
use Illuminate\Console\Command;

class FetchSnelStartRelaties extends Command
{
    protected $signature = 'snelstart:fetch-relaties';

    protected $description = 'Haalt de klanten van de actieve klant op uit SnelStart en slaat ze lokaal op.';

    public function handle(SnelStartImport $import): int
    {
        $counts = $import->customers();

        $this->info($counts['created'] . ' nieuw, ' . $counts['updated'] . ' bijgewerkt.');

        return self::SUCCESS;
    }
}

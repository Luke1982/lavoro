<?php

namespace App\Console\Commands;

use App\Services\SnelStartImport;
use Illuminate\Console\Command;

class FetchSnelStartArtikelen extends Command
{
    protected $signature = 'snelstart:fetch-artikelen';

    protected $description = 'Haalt de artikelen van de actieve klant op uit SnelStart en slaat ze lokaal op.';

    public function handle(SnelStartImport $import): int
    {
        $counts = $import->materials();

        $this->info($counts['created'] . ' nieuw, ' . $counts['updated'] . ' bijgewerkt.');

        return self::SUCCESS;
    }
}

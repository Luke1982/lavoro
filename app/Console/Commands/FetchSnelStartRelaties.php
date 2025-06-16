<?php

namespace App\Console\Commands;

// use App\Models\Relatie;
use App\Services\SnelStartClient;
use Illuminate\Console\Command;

class FetchSnelStartRelaties extends Command
{
    protected $signature = 'snelstart:fetch-relaties';
    protected $description = 'Haalt relaties op uit SnelStart en slaat ze lokaal op.';

    public function handle(SnelStartClient $client)
    {
        $this->info('Ophalen SnelStart relaties…');
        $data = $client->get('/relaties', [
            '$top'    => 500,
            '$filter' => "Relatiesoort/any(r:r eq 'Klant')",
        ]);

        $this->info(var_export($data, true));

        foreach ($data as $item) {
            $this->info($item['id']);
        }
    }
}

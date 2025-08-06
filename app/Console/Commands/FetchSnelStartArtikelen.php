<?php

namespace App\Console\Commands;

use App\Models\Material;
use App\Services\SnelStartClient;
use Illuminate\Console\Command;

class FetchSnelStartArtikelen extends Command
{
    protected $signature = 'snelstart:fetch-artikelen';
    protected $description = 'Haalt artikelen op uit SnelStart en slaat ze lokaal op.';

    public function handle(SnelStartClient $client)
    {
        $this->info('Ophalen SnelStart artikelen…');
        $data = $client->get('/artikelen');

        foreach ($data as $item) {
            Material::updateOrCreate(
                ['snelstart_id' => $item['id']],
                [
                    'name' => $item['omschrijving'],
                    'code' => $item['artikelcode'],
                    'price' => $item['verkoopprijs'],
                    'cost_price' => $item['inkoopprijs'],
                    'stock' => $item['vrijeVoorraad'],
                ]
            );
        }
    }
}

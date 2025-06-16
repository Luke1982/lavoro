<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\SnelStartClient;
use Illuminate\Console\Command;

class FetchSnelStartRelaties extends Command
{
    protected $signature = 'snelstart:fetch-relaties';
    protected $description = 'Haalt relaties op uit SnelStart en slaat ze lokaal op.';
    protected $skip_names = [
        'Klant onbekend',
        '<Vul hier uw bedrijfsnaam in>',
    ];

    public function handle(SnelStartClient $client)
    {
        $this->info('Ophalen SnelStart relaties…');
        $data = $client->get('/relaties', [
            '$top'    => 500,
            '$filter' => "Relatiesoort/any(r:r eq 'Klant')",
        ]);

        foreach ($data as $item) {
            if (in_array($item['naam'], $this->skip_names, true)) {
                $this->warn("Relatie '{$item['naam']}' wordt overgeslagen.");
                continue;
            }
            $vestUuid = data_get($item, 'vestigingsAdres.land.id');
            $postUuid = data_get($item, 'correspondentieAdres.land.id');

            $vestLand = $vestUuid ? $client->getCountry($vestUuid) : [];
            $postLand  = $postUuid  ? $client->getCountry($postUuid)  : [];
            Customer::updateOrCreate(
                ['snelstart_id' => $item['id']],
                [
                    'name'                     => $item['naam'],
                    'email'                    => $item['email'],
                    'invoice_email'            => $item['factuurEmailVersturen']['email'] ?? null,
                    'quotes_email'             => $item['offerteEmailVersturen']['email'] ?? null,
                    'phone'                    => $item['telefoon'],
                    'mobile'                   => $item['mobieleTelefoon'],
                    'website'                  => $item['websiteUrl'],
                    'address'                  => $item['vestigingsAdres']['straat'] ?? null,
                    'postal_code'              => $item['vestigingsAdres']['postcode'] ?? null,
                    'city'                     => $item['vestigingsAdres']['plaats'] ?? null,
                    'country'                  => $vestLand['landcodeISO']   ?? null,
                    'postal_address'           => $item['correspondentieAdres']['straat'] ?? null,
                    'postal_postal_code'       => $item['correspondentieAdres']['postcode'] ?? null,
                    'postal_city'              => $item['correspondentieAdres']['plaats'] ?? null,
                    'postal_country'           => $postLand['landcodeISO']   ?? null,
                    'iban'                     => $item['iban'],
                    'vat_number'               => $item['btwNummer'],
                    'chamber_of_commerce_number' => $item['kvkNummer'],
                    'contactname'              => $item['vestigingsAdres']['contactpersoon'] ?? null,
                ]
            );
        }
    }
}

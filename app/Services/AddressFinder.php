<?php

namespace App\Services;

use App\Exceptions\Refusal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dutch addresses as someone types them, from the Locatieserver of PDOK: the
 * government's register of every address in the country, free and without a
 * key. Nominatim, which places addresses elsewhere in the app, does not allow
 * searching as you type.
 *
 * Only addresses in the Netherlands; anything else is typed in by hand.
 */
class AddressFinder
{
    private const URL = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1';

    private const UNREACHABLE = 'Adressen opzoeken lukt nu niet. Typ het adres zelf in.';

    /**
     * Up to seven addresses that match what was typed so far. When PDOK does
     * not answer the list stays empty: typing the address in goes on working.
     *
     * @return list<array{id: string, name: string}>
     */
    public function suggest(string $query): array
    {
        try {
            $docs = $this->ask('suggest', ['q' => $query, 'fq' => 'type:adres', 'rows' => 7]);
        } catch (Refusal) {
            return [];
        }

        return array_map(fn (array $doc) => ['id' => $doc['id'], 'name' => $doc['weergavenaam']], $docs);
    }

    /**
     * The address behind a suggestion, split the way the forms keep it, and
     * where it lies.
     *
     * @return array{label: string, address: string, postal_code: string, city: string, lat: float, lon: float}|null
     */
    public function find(string $id): ?array
    {
        $doc = $this->ask('lookup', [
            'id' => $id,
            'fl' => 'weergavenaam,straatnaam,huis_nlt,postcode,woonplaatsnaam,centroide_ll',
        ])[0] ?? null;

        if (!$doc || !preg_match('/POINT\((-?[\d.]+) (-?[\d.]+)\)/', $doc['centroide_ll'] ?? '', $point)) {
            return null;
        }

        return [
            'label' => $doc['weergavenaam'],
            'address' => trim(($doc['straatnaam'] ?? '') . ' ' . ($doc['huis_nlt'] ?? '')),
            'postal_code' => $doc['postcode'] ?? '',
            'city' => $doc['woonplaatsnaam'] ?? '',
            'lat' => (float) $point[2],
            'lon' => (float) $point[1],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function ask(string $endpoint, array $query): array
    {
        try {
            $response = Http::acceptJson()->timeout(5)->get(self::URL . '/' . $endpoint, $query);
        } catch (ConnectionException $exception) {
            Log::warning('PDOK did not answer', ['endpoint' => $endpoint, 'error' => $exception->getMessage()]);

            throw new Refusal(self::UNREACHABLE);
        }

        if (!$response->successful()) {
            Log::warning('PDOK refused', ['endpoint' => $endpoint, 'status' => $response->status()]);

            throw new Refusal(self::UNREACHABLE);
        }

        return $response->json('response.docs') ?? [];
    }
}

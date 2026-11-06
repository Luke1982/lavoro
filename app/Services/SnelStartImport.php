<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Material;
use Generator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Customers and materials from SnelStart into this customer's own database. The
 * buttons on the customers and materials pages and the nightly job all come
 * through here.
 */
class SnelStartImport
{
    /**
     * Whether the nightly run overwrites a customer that is already here with
     * what SnelStart has. Set per customer on the settings page; the button
     * always does.
     */
    public const NIGHTLY_UPDATES_CUSTOMERS = 'snelstart_nightly_update_customers';

    /** The most SnelStart hands out per request. */
    private const PAGE_SIZE = 500;

    private const SKIPPED_NAMES = [
        'Klant onbekend',
        '<Vul hier uw bedrijfsnaam in>',
    ];

    public function __construct(private SnelStartClient $client) {}

    /**
     * In one transaction, like materials(): a failure on the third page would
     * otherwise leave the first two imported and the rest not.
     *
     * @return array{created: int, updated: int}
     */
    public function customers(bool $update_existing = true): array
    {
        return DB::transaction(fn () => $this->importCustomers($update_existing));
    }

    /**
     * @return array{created: int, updated: int}
     */
    public function materials(): array
    {
        return DB::transaction(fn () => $this->importMaterials());
    }

    private function importCustomers(bool $update_existing): array
    {
        $counts = ['created' => 0, 'updated' => 0];

        $relations = $this->pages('/relaties', ['$filter' => "Relatiesoort/any(r:r eq 'Klant')"]);

        foreach ($relations as $relation) {
            if (in_array($relation['naam'] ?? null, self::SKIPPED_NAMES, true)) {
                continue;
            }

            $customer = Customer::firstOrNew(['snelstart_id' => $relation['id']]);

            if ($customer->exists && !$update_existing) {
                continue;
            }

            $customer->fill($this->customerFields($relation));

            if (!$customer->exists || $customer->isDirty()) {
                $counts[$customer->exists ? 'updated' : 'created']++;
                $customer->save();
            }
        }

        return $counts;
    }

    private function importMaterials(): array
    {
        $counts = ['created' => 0, 'updated' => 0];

        foreach ($this->pages('/artikelen') as $article) {
            $material = Material::firstOrNew(['snelstart_id' => $article['id']]);

            $material->fill([
                'name' => $article['omschrijving'] ?? null,
                'code' => $article['artikelcode'] ?? null,
                'price' => $article['verkoopprijs'] ?? null,
                'cost_price' => $article['inkoopprijs'] ?? null,
                'stock' => $article['vrijeVoorraad'] ?? 0,
            ]);

            if (!$material->exists || $material->isDirty()) {
                $counts[$material->exists ? 'updated' : 'created']++;
                $material->save();
            }
        }

        return $counts;
    }

    private function customerFields(array $relation): array
    {
        return [
            'name' => $relation['naam'] ?? null,
            'email' => $relation['email'] ?? null,
            'invoice_email' => data_get($relation, 'factuurEmailVersturen.email'),
            'quotes_email' => data_get($relation, 'offerteEmailVersturen.email'),
            'phone' => $relation['telefoon'] ?? null,
            'mobile' => $relation['mobieleTelefoon'] ?? null,
            'website' => $relation['websiteUrl'] ?? null,
            'address' => data_get($relation, 'vestigingsAdres.straat'),
            'postal_code' => data_get($relation, 'vestigingsAdres.postcode'),
            'city' => data_get($relation, 'vestigingsAdres.plaats'),
            'country' => $this->countryCode(data_get($relation, 'vestigingsAdres.land.id')),
            'postal_address' => data_get($relation, 'correspondentieAdres.straat'),
            'postal_postal_code' => data_get($relation, 'correspondentieAdres.postcode'),
            'postal_city' => data_get($relation, 'correspondentieAdres.plaats'),
            'postal_country' => $this->countryCode(data_get($relation, 'correspondentieAdres.land.id')),
            'iban' => $relation['iban'] ?? null,
            'vat_number' => $relation['btwNummer'] ?? null,
            'chamber_of_commerce_number' => $relation['kvkNummer'] ?? null,
            'contactname' => data_get($relation, 'vestigingsAdres.contactpersoon'),
        ];
    }

    private function countryCode(?string $snelstart_id): ?string
    {
        return $snelstart_id ? $this->client->getCountry($snelstart_id)['landcodeISO'] ?? null : null;
    }

    /**
     * Every record, page by page: one request returns at most 500, and
     * everything after that was silently left out before.
     */
    private function pages(string $uri, array $query = []): Generator
    {
        $skip = 0;
        $previous_first = null;

        do {
            $page = $this->client->get($uri, $query + ['$top' => self::PAGE_SIZE, '$skip' => $skip]) ?? [];

            if ($page === []) {
                return;
            }

            if (($page[0]['id'] ?? null) === $previous_first) {
                throw new RuntimeException("SnelStart returned the same page of {$uri} twice: \$skip was ignored.");
            }

            $previous_first = $page[0]['id'] ?? null;
            $skip += self::PAGE_SIZE;

            yield from $page;
        } while (count($page) === self::PAGE_SIZE);
    }
}

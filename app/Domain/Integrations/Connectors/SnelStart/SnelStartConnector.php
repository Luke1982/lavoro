<?php

namespace App\Domain\Integrations\Connectors\SnelStart;

use App\Domain\Integrations\ChangeDetection;
use App\Domain\Integrations\Connector;
use App\Domain\Integrations\Contracts\PullsCustomers;
use App\Domain\Integrations\Contracts\PullsMaterials;
use App\Domain\Integrations\Contracts\PushesCustomers;
use App\Domain\Integrations\Contracts\ReceivesSalesDocuments;
use App\Domain\Integrations\Data\CustomerData;
use App\Domain\Integrations\Data\ExternalRecord;
use App\Domain\Integrations\Data\Field;
use App\Domain\Integrations\Data\MaterialData;
use App\Domain\Integrations\Data\PushedDocument;
use App\Domain\Integrations\Data\SalesDocumentData;
use App\Domain\Integrations\Data\SalesLineData;
use App\Domain\Integrations\DocumentForm;
use App\Exceptions\Refusal;
use Carbon\CarbonImmutable;

/**
 * SnelStart, the bookkeeping package. Customers both ways, articles in, and a
 * werkbon out as a sales order.
 *
 * SnelStart has no webhooks and no reliable "changed since", so customers and
 * articles are fetched in full every night and compared.
 */
class SnelStartConnector extends Connector implements PullsCustomers, PullsMaterials, PushesCustomers, ReceivesSalesDocuments
{
    /** Placeholder relations every SnelStart administration starts with. */
    private const SKIPPED_NAMES = [
        'Klant onbekend',
        '<Vul hier uw bedrijfsnaam in>',
    ];

    private ?SnelStartApi $api = null;

    public static function key(): string
    {
        return 'snelstart';
    }

    public static function label(): string
    {
        return 'SnelStart';
    }

    public static function description(): string
    {
        return 'Boekhouding. Klanten twee kanten op, artikelen naar Lavoro, en een werkbon als verkooporder.';
    }

    public static function credentialFields(): array
    {
        return [
            new Field('client_key', 'Clientsleutel', secret: true,
                help: 'In SnelStart onder Instellingen → Koppelingen; maak daar een koppeling voor Lavoro.'),
            new Field('subscription_key', 'Abonnementssleutel', secret: true,
                help: 'De sleutel van het SnelStart-ontwikkelaarsabonnement.'),
        ];
    }

    public function test(): void
    {
        $this->api()->get('/relaties', ['$top' => 1]);
    }

    public static function customerChanges(): ChangeDetection
    {
        return ChangeDetection::Full;
    }

    public static function materialChanges(): ChangeDetection
    {
        return ChangeDetection::Full;
    }

    public static function documentForms(): array
    {
        return [DocumentForm::SalesOrder];
    }

    public function pullCustomers(?CarbonImmutable $since = null): iterable
    {
        foreach ($this->api()->pages('/relaties', ['$filter' => "Relatiesoort/any(r:r eq 'Klant')"]) as $relation) {
            if (in_array($relation['naam'] ?? null, self::SKIPPED_NAMES, true)) {
                continue;
            }

            yield $this->customerRecord($relation);
        }
    }

    public function fetchCustomer(string $id): ?ExternalRecord
    {
        $relation = $this->api()->find('/relaties/' . $id);

        return $relation ? $this->customerRecord($relation) : null;
    }

    public function createCustomer(CustomerData $customer): ExternalRecord
    {
        $relation = $this->api()->post('/relaties', $this->relation(['relatiesoort' => ['Klant']], $customer));

        return $this->customerRecord($relation);
    }

    /**
     * SnelStart replaces a relation whole on PUT, so the relation is read
     * first and only Lavoro's fields are laid over it. Anything else on it --
     * payment terms, a ledger account, how invoices are sent -- stays.
     */
    public function updateCustomer(string $id, CustomerData $customer): ExternalRecord
    {
        $current = $this->api()->get('/relaties/' . $id);
        $relation = $this->api()->put('/relaties/' . $id, $this->relation($current, $customer));

        return $this->customerRecord($relation ?? $current);
    }

    public function pullMaterials(?CarbonImmutable $since = null): iterable
    {
        foreach ($this->api()->pages('/artikelen') as $article) {
            yield $this->materialRecord($article);
        }
    }

    public function fetchMaterial(string $id): ?ExternalRecord
    {
        $article = $this->api()->find('/artikelen/' . $id);

        return $article ? $this->materialRecord($article) : null;
    }

    /**
     * The sales order exactly as the werkbon button has always made it. Every
     * line needs an article in SnelStart; a material without one is left off
     * and reported, and with none at all nothing is sent.
     */
    public function pushSalesDocument(SalesDocumentData $document, DocumentForm $form): PushedDocument
    {
        $lines = [];
        $warnings = [];
        $total = 0.0;
        $total_with_vat = 0.0;

        foreach ($document->lines as $line) {
            if (!$line->article_id) {
                $warnings[] = 'Materiaal zonder SnelStart-koppeling overgeslagen: ' . $line->description;

                continue;
            }

            $total += $line->total();
            $total_with_vat += $line->total() * (1 + $line->vat_percent / 100);
            $lines[] = $this->orderLine($line);
        }

        if ($lines === []) {
            throw new Refusal('Geen materialen met een SnelStart-koppeling op deze werkbon. '
                . 'Haal de materialen op uit SnelStart en gebruik die op de werkbon.');
        }

        $order = $this->api()->post('/verkooporders', [
            'relatie' => ['id' => $document->customer_id, 'uri' => '/v2/relaties/' . $document->customer_id],
            'procesStatus' => 'Order',
            'datum' => $document->date->toDateString(),
            'omschrijving' => $document->description,
            'orderreferentie' => $document->reference,
            'memo' => $document->memo,
            'verkooporderBtwIngaveModel' => 'Inclusief',
            'verkoopOrderStatus' => 'InBehandeling',
            'regels' => $lines,
            'totaalExclusiefBtw' => round($total, 2),
            'totaalInclusiefBtw' => round($total_with_vat, 2),
            'afleveradres' => $this->orderAddress($document->customer, false),
            'factuuradres' => $this->orderAddress($document->customer, true),
            'extraHoofdVelden' => [],
        ]);

        return new PushedDocument(
            id: (string) ($order['id'] ?? throw new Refusal('SnelStart gaf geen id terug voor de verkooporder.')),
            number: isset($order['nummer']) ? (string) $order['nummer'] : null,
            warnings: $warnings,
        );
    }

    private function customerRecord(array $relation): ExternalRecord
    {
        return new ExternalRecord((string) $relation['id'], CustomerData::from([
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
            'country' => $this->iso(data_get($relation, 'vestigingsAdres.land.id')),
            'postal_address' => data_get($relation, 'correspondentieAdres.straat'),
            'postal_postal_code' => data_get($relation, 'correspondentieAdres.postcode'),
            'postal_city' => data_get($relation, 'correspondentieAdres.plaats'),
            'postal_country' => $this->iso(data_get($relation, 'correspondentieAdres.land.id')),
            'iban' => $relation['iban'] ?? null,
            'vat_number' => $relation['btwNummer'] ?? null,
            'chamber_of_commerce_number' => $relation['kvkNummer'] ?? null,
            'contactname' => data_get($relation, 'vestigingsAdres.contactpersoon'),
        ]));
    }

    /**
     * Lavoro's fields laid over a relation. The e-mail addresses for invoices
     * and quotes live inside SnelStart's "send by e-mail" blocks, which carry
     * more than the address; they are only touched when the block is there.
     */
    private function relation(array $relation, CustomerData $customer): array
    {
        $relation['naam'] = $customer->name;
        $relation['email'] = $customer->email;
        $relation['telefoon'] = $customer->phone;
        $relation['mobieleTelefoon'] = $customer->mobile;
        $relation['websiteUrl'] = $customer->website;
        $relation['iban'] = $customer->iban;
        $relation['btwNummer'] = $customer->vat_number;
        $relation['kvkNummer'] = $customer->chamber_of_commerce_number;
        $relation['vestigingsAdres'] = $this->address($relation['vestigingsAdres'] ?? [], [
            'contactpersoon' => $customer->contactname,
            'straat' => $customer->address,
            'postcode' => $customer->postal_code,
            'plaats' => $customer->city,
        ], $customer->country);
        $relation['correspondentieAdres'] = $this->address($relation['correspondentieAdres'] ?? [], [
            'contactpersoon' => $customer->contactname,
            'straat' => $customer->postal_address,
            'postcode' => $customer->postal_postal_code,
            'plaats' => $customer->postal_city,
        ], $customer->postal_country);

        if (isset($relation['factuurEmailVersturen'])) {
            $relation['factuurEmailVersturen']['email'] = $customer->invoice_email;
        }

        if (isset($relation['offerteEmailVersturen'])) {
            $relation['offerteEmailVersturen']['email'] = $customer->quotes_email;
        }

        return $relation;
    }

    private function address(array $current, array $fields, ?string $iso): array
    {
        $address = [...$current, ...$fields];
        $country = $iso ? $this->api()->countryByIso($iso) : null;

        if ($country) {
            $address['land'] = ['id' => $country['id'], 'uri' => '/v2/landen/' . $country['id']];
        }

        return $address;
    }

    private function orderAddress(CustomerData $customer, bool $invoice): array
    {
        $address = [
            'contactpersoon' => $customer->contactname ?? $customer->name,
            'straat' => $invoice ? ($customer->postal_address ?: $customer->address) : $customer->address,
            'postcode' => $invoice ? ($customer->postal_postal_code ?: $customer->postal_code) : $customer->postal_code,
            'plaats' => $invoice ? ($customer->postal_city ?: $customer->city) : $customer->city,
        ];

        $iso = $invoice ? $customer->postal_country : $customer->country;
        $country = $iso ? $this->api()->countryByIso($iso) : null;

        if ($country) {
            $address['land'] = ['id' => $country['id'], 'uri' => '/v2/landen/' . $country['id']];
        }

        return $address;
    }

    private function orderLine(SalesLineData $line): array
    {
        return [
            'artikel' => ['id' => $line->article_id, 'uri' => '/v2/artikelen/' . $line->article_id],
            'omschrijving' => $line->description,
            'stuksprijs' => $line->unit_price,
            'aantal' => $line->quantity,
            'kortingsPercentage' => 0,
            'totaal' => $line->total(),
            'extraRegelVelden' => [],
        ];
    }

    private function materialRecord(array $article): ExternalRecord
    {
        return new ExternalRecord((string) $article['id'], MaterialData::from([
            'name' => $article['omschrijving'] ?? null,
            'code' => $article['artikelcode'] ?? null,
            'price' => $article['verkoopprijs'] ?? null,
            'cost_price' => $article['inkoopprijs'] ?? null,
            'stock' => $article['vrijeVoorraad'] ?? 0,
        ]));
    }

    private function iso(?string $country_id): ?string
    {
        return $country_id ? ($this->api()->country($country_id)['landcodeISO'] ?? null) : null;
    }

    private function api(): SnelStartApi
    {
        if ($this->api) {
            return $this->api;
        }

        $client_key = $this->credential('client_key');
        $subscription_key = $this->credential('subscription_key');

        if (!$client_key || !$subscription_key) {
            throw new Refusal('SnelStart is niet ingesteld: de sleutels ontbreken. Vul ze in onder Instellingen → Koppelingen.');
        }

        return $this->api = new SnelStartApi($client_key, $subscription_key);
    }
}

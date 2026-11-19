<?php

namespace App\Domain\Integrations\Connectors\EBoekhouden;

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
use App\Domain\Integrations\RecordNotFound;
use App\Exceptions\Refusal;
use App\Services\EBoekhouden\EBoekhoudenClient;
use Carbon\CarbonImmutable;

/**
 * e-Boekhouden.nl, for a customer's own administration -- not the one the
 * panel books MajorLabel's invoices in, which runs on the account in .env.
 *
 * Relations both ways, articles in, and a werkbon out as an invoice made from
 * one of their invoice templates: left unbooked for someone to check (draft) or
 * booked straight away (final). It has no sales orders.
 *
 * A relation is known here by its ID, which does not change; its code is what
 * an invoice needs, and is looked up when one goes out.
 */
class EBoekhoudenConnector extends Connector implements PullsCustomers, PullsMaterials, PushesCustomers, ReceivesSalesDocuments
{
    /** A customer's field here => the relation's field there. */
    private const RELATION = [
        'name' => 'Bedrijf',
        'contactname' => 'Contactpersoon',
        'email' => 'Email',
        'phone' => 'Telefoon',
        'mobile' => 'GSM',
        'website' => 'Site',
        'address' => 'Adres',
        'postal_code' => 'Postcode',
        'city' => 'Plaats',
        'country' => 'Land',
        'postal_address' => 'Adres2',
        'postal_postal_code' => 'Postcode2',
        'postal_city' => 'Plaats2',
        'postal_country' => 'Land2',
        'iban' => 'IBAN',
        'vat_number' => 'BTWNummer',
        'chamber_of_commerce_number' => 'KvkNummer',
    ];

    private ?EBoekhoudenClient $client = null;

    public static function key(): string
    {
        return 'eboekhouden';
    }

    public static function label(): string
    {
        return 'e-Boekhouden.nl';
    }

    public static function description(): string
    {
        return 'Boekhouding. Relaties twee kanten op, artikelen naar Lavoro, en een werkbon als factuur.';
    }

    public static function credentialFields(): array
    {
        return [
            new Field('username', 'Gebruikersnaam',
                help: 'In e-Boekhouden onder Beheer → Inrichting → Instellingen → Koppelingen → API/SOAP.'),
            new Field('security_code_1', 'Beveiligingscode 1', secret: true),
            new Field('security_code_2', 'Beveiligingscode 2', secret: true),
        ];
    }

    public static function optionFields(): array
    {
        return [
            new Field('invoice_template', 'Factuursjabloon', required: false,
                help: 'De naam van het sjabloon in e-Boekhouden waarmee werkbonnen een factuur worden. Nodig om werkbonnen te versturen.'),
            new Field('vat_code', 'Btw-code', default: 'HOOG_VERK_21',
                help: 'Voor elke regel; HOOG_VERK_21 is 21% omzetbelasting.'),
            new Field('revenue_account', 'Omzetrekening', default: '8000'),
            new Field('payment_days', 'Betalingstermijn in dagen', default: '14', rules: ['integer', 'between:0,365']),
        ];
    }

    public static function customerFields(): array
    {
        return array_keys(self::RELATION);
    }

    public static function materialFields(): array
    {
        return ['name', 'code', 'price', 'cost_price'];
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
        return [DocumentForm::DraftInvoice, DocumentForm::FinalInvoice];
    }

    public function test(): void
    {
        $this->client()->verify();
    }

    public function pullCustomers(?CarbonImmutable $since = null): iterable
    {
        foreach ($this->client()->fullRelations() as $relation) {
            yield $this->customerRecord($relation);
        }
    }

    public function fetchCustomer(string $id): ?ExternalRecord
    {
        $relation = $this->relation($id);

        return $relation ? $this->customerRecord($relation) : null;
    }

    public function createCustomer(CustomerData $customer): ExternalRecord
    {
        $fields = $this->overlay(['Code' => 'LAV' . $customer->reference], $customer);
        $fields['ID'] = $this->client()->saveRelation($fields);

        return $this->customerRecord($fields);
    }

    public function updateCustomer(string $id, CustomerData $customer): ExternalRecord
    {
        $current = $this->relation($id) ?? throw new RecordNotFound('Deze relatie bestaat niet meer in e-Boekhouden.');
        $fields = $this->overlay($current, $customer);
        $this->client()->saveRelation($fields);

        return $this->customerRecord($fields);
    }

    /** Inactive articles stay where they are: nobody should book them on a werkbon. */
    public function pullMaterials(?CarbonImmutable $since = null): iterable
    {
        foreach ($this->client()->articles() as $article) {
            if ($article['active']) {
                yield $this->materialRecord($article);
            }
        }
    }

    public function fetchMaterial(string $id): ?ExternalRecord
    {
        $article = collect($this->client()->articles((int) $id))->firstWhere('id', $id);

        return $article ? $this->materialRecord($article) : null;
    }

    public function pushSalesDocument(SalesDocumentData $document, DocumentForm $form): PushedDocument
    {
        $template = $this->option('invoice_template')
            ?? throw new Refusal('Kies eerst een factuursjabloon voor e-Boekhouden onder Instellingen → Koppelingen.');

        if ($document->lines === []) {
            throw new Refusal('Er staat geen regel op deze werkbon die gefactureerd kan worden.');
        }

        $relation = $this->relation($document->customer_id)
            ?? throw new Refusal('De klant bestaat niet meer in e-Boekhouden.');

        $number = $this->client()->addSalesInvoice([
            'relation_code' => $relation['Code'],
            'date' => $document->date->toDateString(),
            'payment_days' => (int) $this->option('payment_days'),
            'template' => $template,
            'description' => $document->description . ($document->memo ? ' (' . $document->memo . ')' : ''),
            'book' => $form === DocumentForm::FinalInvoice,
            'lines' => array_map(fn (SalesLineData $line) => [
                'quantity' => round($line->quantity, 4),
                'unit' => 'st',
                'code' => (string) $line->code,
                'description' => $line->description,
                'price' => round($line->unit_price, 4),
                'vat_code' => (string) $this->option('vat_code'),
                'revenue_account' => (string) $this->option('revenue_account'),
            ], $document->lines),
        ]);

        if ($number === '') {
            throw new Refusal('e-Boekhouden gaf geen factuurnummer terug.');
        }

        return new PushedDocument(id: $number, number: $number);
    }

    private function relation(string $id): ?array
    {
        return collect($this->client()->fullRelations((int) $id))->firstWhere('ID', $id);
    }

    /** @param  array<string, string>  $relation */
    private function customerRecord(array $relation): ExternalRecord
    {
        $values = [];

        foreach (self::RELATION as $field => $theirs) {
            $values[$field] = $relation[$theirs] ?? null;
        }

        return new ExternalRecord((string) $relation['ID'], CustomerData::from($values));
    }

    /** @param  array<string, mixed>  $relation */
    private function overlay(array $relation, CustomerData $customer): array
    {
        foreach (self::RELATION as $field => $theirs) {
            $relation[$theirs] = (string) $customer->{$field};
        }

        return $relation;
    }

    private function materialRecord(array $article): ExternalRecord
    {
        return new ExternalRecord($article['id'], MaterialData::from([
            'name' => $article['name'],
            'code' => $article['code'],
            'price' => $article['price'],
            'cost_price' => $article['cost_price'],
        ]));
    }

    private function client(): EBoekhoudenClient
    {
        return $this->client ??= EBoekhoudenClient::forCustomer(
            $this->credential('username'),
            $this->credential('security_code_1'),
            $this->credential('security_code_2'),
        );
    }
}

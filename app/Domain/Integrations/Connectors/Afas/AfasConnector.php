<?php

namespace App\Domain\Integrations\Connectors\Afas;

use App\Domain\Integrations\ChangeDetection;
use App\Domain\Integrations\Connector;
use App\Domain\Integrations\Contracts\PullsCustomers;
use App\Domain\Integrations\Contracts\PullsMaterials;
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
 * AFAS Profit. Debtors and articles come in; a werkbon goes out as a sales
 * order. Lavoro does not send customers to AFAS: a debtor there carries
 * mandatory parts (payment terms, VAT duty, a postal address) that are the
 * administration's to decide, not Lavoro's.
 *
 * Profit has no standard GetConnectors to read from. The customer defines two
 * in Profit, gives their fields the ids below (Profit lets you set a field id
 * when defining one), and adds both to the app connector with FbSales. "Test"
 * says what is missing.
 */
class AfasConnector extends Connector implements PullsCustomers, PullsMaterials, ReceivesSalesDocuments
{
    /** A customer's field here => the field id in the customer GetConnector. */
    private const DEBTOR = [
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'website' => 'Website',
        'address' => 'Street',
        'postal_code' => 'PostalCode',
        'city' => 'City',
        'country' => 'CountryId',
        'vat_number' => 'VatNumber',
        'chamber_of_commerce_number' => 'CocNumber',
    ];

    /** A material's field here => the field id in the article GetConnector. */
    private const ARTICLE = [
        'name' => 'Description',
        'code' => 'ItemCode',
        'price' => 'SalesPrice',
    ];

    private const DEBTOR_ID = 'DebtorId';

    private const ARTICLE_BLOCKED = 'Blocked';

    private const MODIFIED = 'ModifiedDate';

    /** AFAS's filter operator codes. */
    private const EQUALS = 1;

    private const GREATER_THAN = 4;

    /** "Artikel" as the kind of item on an order line. */
    private const ITEM_TYPE_ARTICLE = '2';

    private ?AfasApi $api = null;

    public static function key(): string
    {
        return 'afas';
    }

    public static function label(): string
    {
        return 'AFAS Profit';
    }

    public static function description(): string
    {
        return 'ERP en boekhouding. Debiteuren en artikelen naar Lavoro, en een werkbon als verkooporder.';
    }

    public static function credentialFields(): array
    {
        return [
            new Field('environment', 'Omgevingsnummer', rules: ['regex:/^\d+$/'],
                help: 'Alleen de cijfers uit de omgevingscode: 12345 uit O12345AA.'),
            new Field('token', 'Token', secret: true,
                help: 'De token van een gebruiker in de app-connector voor Lavoro (Algemeen → Beheer → App-connector).'),
        ];
    }

    public static function optionFields(): array
    {
        return [
            new Field('environment_type', 'Omgeving', default: 'production', rules: ['in:production,test,accept'],
                help: 'production, test of accept.'),
            new Field('customers_connector', 'GetConnector voor debiteuren', default: 'Lavoro_Debiteuren', rules: ['regex:/^[A-Za-z0-9_]+$/'],
                help: 'Met de veld-ID\'s ' . self::DEBTOR_ID . ', ' . implode(', ', self::DEBTOR) . ' en ' . self::MODIFIED . '.'),
            new Field('materials_connector', 'GetConnector voor artikelen', default: 'Lavoro_Artikelen', rules: ['regex:/^[A-Za-z0-9_]+$/'],
                help: 'Met de veld-ID\'s ' . implode(', ', self::ARTICLE) . ', ' . self::ARTICLE_BLOCKED . ' en ' . self::MODIFIED . '.'),
        ];
    }

    public static function customerFields(): array
    {
        return array_keys(self::DEBTOR);
    }

    public static function materialFields(): array
    {
        return array_keys(self::ARTICLE);
    }

    public static function customerChanges(): ChangeDetection
    {
        return ChangeDetection::Delta;
    }

    public static function materialChanges(): ChangeDetection
    {
        return ChangeDetection::Delta;
    }

    public static function documentForms(): array
    {
        return [DocumentForm::SalesOrder];
    }

    /**
     * Logs in, and checks that both GetConnectors are there with every field
     * id Lavoro reads. A field that is missing would otherwise read as empty,
     * and empty it in Lavoro.
     */
    public function test(): void
    {
        $missing = [];

        foreach ([
            $this->option('customers_connector') => [self::DEBTOR_ID, ...array_values(self::DEBTOR), self::MODIFIED],
            $this->option('materials_connector') => [...array_values(self::ARTICLE), self::ARTICLE_BLOCKED, self::MODIFIED],
        ] as $connector => $fields) {
            $present = array_column($this->api()->metainfo('/get/' . rawurlencode($connector))['fields'] ?? [], 'id');
            $absent = array_diff($fields, $present);

            if ($absent !== []) {
                $missing[] = $connector . ' mist ' . implode(', ', $absent);
            }
        }

        if ($missing !== []) {
            throw new Refusal('De aanmelding werkt, maar de GetConnectors zijn niet compleet: ' . implode('; ', $missing) . '.');
        }
    }

    public function pullCustomers(?CarbonImmutable $since = null): iterable
    {
        foreach ($this->api()->pages($this->option('customers_connector'), self::DEBTOR_ID, $this->changedSince($since)) as $row) {
            yield $this->customerRecord($row);
        }
    }

    public function fetchCustomer(string $id): ?ExternalRecord
    {
        $row = $this->api()->rows($this->option('customers_connector'), $this->where(self::DEBTOR_ID, $id))[0] ?? null;

        return $row ? $this->customerRecord($row) : null;
    }

    /** Blocked articles stay where they are: nobody should book them on a werkbon. */
    public function pullMaterials(?CarbonImmutable $since = null): iterable
    {
        foreach ($this->api()->pages($this->option('materials_connector'), self::ARTICLE['code'], $this->changedSince($since)) as $row) {
            if (!($row[self::ARTICLE_BLOCKED] ?? false)) {
                yield $this->materialRecord($row);
            }
        }
    }

    public function fetchMaterial(string $id): ?ExternalRecord
    {
        $row = $this->api()->rows($this->option('materials_connector'), $this->where(self::ARTICLE['code'], $id))[0] ?? null;

        return $row && !($row[self::ARTICLE_BLOCKED] ?? false) ? $this->materialRecord($row) : null;
    }

    /**
     * A sales order with an article line per material. Profit takes the rest
     * from the debtor and the article; the order number comes back when Profit
     * hands it out.
     */
    public function pushSalesDocument(SalesDocumentData $document, DocumentForm $form): PushedDocument
    {
        $lines = [];
        $warnings = [];

        foreach ($document->lines as $line) {
            if (!$line->article_id) {
                $warnings[] = 'Materiaal zonder artikel in AFAS overgeslagen: ' . $line->description;

                continue;
            }

            $lines[] = ['Fields' => $this->orderLine($line)];
        }

        if ($lines === []) {
            throw new Refusal('Geen materialen met een artikel in AFAS op deze werkbon. Haal de artikelen op uit AFAS en gebruik die op de werkbon.');
        }

        $answer = $this->api()->insert('FbSales', ['FbSales' => ['Element' => [
            'Fields' => ['DbId' => $document->customer_id, 'OrDa' => $document->date->toDateString()],
            'Objects' => [['FbSalesLines' => ['Element' => $lines]]],
        ]]]);

        $number = self::find($answer, 'OrNu');

        return new PushedDocument($number ?? $document->reference, $number, $number ? $warnings : [...$warnings, 'AFAS gaf geen ordernummer terug.']);
    }

    /** @return array<string, mixed> */
    private function orderLine(SalesLineData $line): array
    {
        return [
            'VaIt' => self::ITEM_TYPE_ARTICLE,
            'ItCd' => $line->article_id,
            'QuUn' => $line->quantity,
            'Upri' => $line->unit_price,
        ];
    }

    private function customerRecord(array $row): ExternalRecord
    {
        $values = [];

        foreach (self::DEBTOR as $field => $theirs) {
            $values[$field] = $row[$theirs] ?? null;
        }

        return new ExternalRecord((string) $row[self::DEBTOR_ID], CustomerData::from($values));
    }

    private function materialRecord(array $row): ExternalRecord
    {
        $values = [];

        foreach (self::ARTICLE as $field => $theirs) {
            $values[$field] = $row[$theirs] ?? null;
        }

        return new ExternalRecord((string) $row[self::ARTICLE['code']], MaterialData::from($values));
    }

    /**
     * Profit reads this moment in its own (Dutch) time, as far as its
     * documentation goes; an hour of extra overlap covers it either way.
     *
     * @return array<string, string>
     */
    private function changedSince(?CarbonImmutable $since): array
    {
        return $since ? $this->where(self::MODIFIED, $since->setTimezone('Europe/Amsterdam')->subHour()->format('Y-m-d\TH:i:s'), self::GREATER_THAN) : [];
    }

    /** @return array<string, string> */
    private function where(string $field, string $value, int $operator = self::EQUALS): array
    {
        return ['filterfieldids' => $field, 'filtervalues' => $value, 'operatortypes' => (string) $operator];
    }

    /** A value anywhere in Profit's answer, whose shape differs per UpdateConnector. */
    private static function find(array $answer, string $key): ?string
    {
        foreach ($answer as $name => $value) {
            if ($name === $key && is_scalar($value) && filled($value)) {
                return (string) $value;
            }

            if (is_array($value) && ($found = self::find($value, $key)) !== null) {
                return $found;
            }
        }

        return null;
    }

    private function api(): AfasApi
    {
        return $this->api ??= new AfasApi(
            (string) $this->credential('environment'),
            (string) $this->option('environment_type'),
            $this->credential('token') ?? throw new Refusal('AFAS is niet ingesteld: de token ontbreekt. Vul hem in onder Instellingen → Koppelingen.'),
        );
    }
}

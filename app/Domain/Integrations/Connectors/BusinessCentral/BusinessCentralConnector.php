<?php

namespace App\Domain\Integrations\Connectors\BusinessCentral;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\ChangeDetection;
use App\Domain\Integrations\Connector;
use App\Domain\Integrations\Contracts\PullsCustomers;
use App\Domain\Integrations\Contracts\PullsMaterials;
use App\Domain\Integrations\Contracts\PushesCustomers;
use App\Domain\Integrations\Contracts\ReceivesSalesDocuments;
use App\Domain\Integrations\Contracts\ReceivesWebhooks;
use App\Domain\Integrations\Data\ChangeNotice;
use App\Domain\Integrations\Data\CustomerData;
use App\Domain\Integrations\Data\ExternalRecord;
use App\Domain\Integrations\Data\Field;
use App\Domain\Integrations\Data\MaterialData;
use App\Domain\Integrations\Data\PushedDocument;
use App\Domain\Integrations\Data\SalesDocumentData;
use App\Domain\Integrations\Data\SalesLineData;
use App\Domain\Integrations\Data\WebhookSubscription;
use App\Domain\Integrations\DocumentForm;
use App\Domain\Integrations\RecordNotFound;
use App\Exceptions\Refusal;
use App\Models\IntegrationWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Microsoft Dynamics 365 Business Central online, through its standard API.
 * Customers both ways, items in as materials, and a werkbon out as a sales
 * order, a draft invoice or a posted invoice. Business Central can say what
 * changed since a moment, and calls Lavoro itself when something changes.
 *
 * The customer registers an app in its own Microsoft Entra tenant, adds it in
 * Business Central under Microsoft Entra-toepassingen with the permission sets
 * it needs, and enters the app's id and secret here.
 */
class BusinessCentralConnector extends Connector implements PullsCustomers, PullsMaterials, PushesCustomers, ReceivesSalesDocuments, ReceivesWebhooks
{
    /** A customer's field here => the customer's field there. */
    private const CUSTOMER = [
        'name' => 'displayName',
        'email' => 'email',
        'phone' => 'phoneNumber',
        'website' => 'website',
        'address' => 'addressLine1',
        'postal_code' => 'postalCode',
        'city' => 'city',
        'country' => 'country',
        'vat_number' => 'taxRegistrationNumber',
    ];

    /** The lists the webhooks watch, per area. */
    private const RESOURCES = [
        'customers' => Area::Customers,
        'items' => Area::Materials,
    ];

    /** Per form: the list a werkbon goes into, the list of its lines, and its date field. */
    private const DOCUMENTS = [
        'sales_order' => ['salesOrders', 'salesOrderLines', 'orderDate'],
        'draft_invoice' => ['salesInvoices', 'salesInvoiceLines', 'invoiceDate'],
        'final_invoice' => ['salesInvoices', 'salesInvoiceLines', 'invoiceDate'],
    ];

    /** Business Central's limits on these fields. */
    private const MAX_EXTERNAL_NUMBER = 35;

    private const MAX_LINE_DESCRIPTION = 100;

    private ?BusinessCentralApi $api = null;

    public static function key(): string
    {
        return 'businesscentral';
    }

    public static function label(): string
    {
        return 'Business Central';
    }

    public static function description(): string
    {
        return 'Microsoft Dynamics 365 Business Central. Klanten twee kanten op, artikelen naar Lavoro, en een werkbon als verkooporder of factuur. Meldt wijzigingen zelf.';
    }

    public static function credentialFields(): array
    {
        return [
            new Field('tenant_id', 'Directory (tenant) ID', rules: ['regex:/^[A-Za-z0-9.-]+$/'],
                help: 'Van de Microsoft Entra-omgeving waar Business Central in draait.'),
            new Field('client_id', 'Application (client) ID', rules: ['uuid'],
                help: 'Van de app-registratie voor Lavoro. Voeg die app in Business Central toe onder Microsoft Entra-toepassingen, met de machtigingensets die hij nodig heeft.'),
            new Field('client_secret', 'Clientgeheim', secret: true),
        ];
    }

    public static function optionFields(): array
    {
        return [
            new Field('environment', 'Omgeving', default: 'production', rules: ['regex:/^[A-Za-z0-9_-]+$/'],
                help: 'De naam van de Business Central-omgeving, zoals production of sandbox.'),
            new Field('company_id', 'Bedrijf (ID)', required: false, rules: ['uuid'], scope: true,
                help: 'Laat leeg en klik op Verbinding testen: de melding noemt dan de bedrijven met hun ID.'),
        ];
    }

    public static function customerFields(): array
    {
        return array_keys(self::CUSTOMER);
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
        return [DocumentForm::SalesOrder, DocumentForm::DraftInvoice, DocumentForm::FinalInvoice];
    }

    public static function webhookAreas(): array
    {
        return array_values(self::RESOURCES);
    }

    public function webhookScope(): string
    {
        return (string) $this->option('company_id');
    }

    /**
     * Logs in, and checks the company. Without one chosen, the refusal lists
     * the companies there are, which is the easiest way to find its id.
     */
    public function test(): void
    {
        $company = $this->option('company_id');

        if ($company) {
            $this->api()->find(BusinessCentralApi::company($company))
                ?? throw new Refusal('Business Central kent geen bedrijf met ID ' . $company . '.');

            return;
        }

        $companies = array_map(
            fn (array $company) => (($company['displayName'] ?? '') ?: ($company['name'] ?? '')) . ' (' . $company['id'] . ')',
            $this->api()->get('/companies')['value'] ?? [],
        );

        throw new Refusal('De aanmelding werkt. Kies nog een bedrijf en vul het ID in: '
            . ($companies === [] ? 'er zijn geen bedrijven zichtbaar voor deze app.' : implode(', ', $companies) . '.'));
    }

    public function pullCustomers(?CarbonImmutable $since = null): iterable
    {
        foreach ($this->api()->pages($this->path('/customers'), $this->changedSince($since)) as $customer) {
            yield $this->customerRecord($customer);
        }
    }

    public function fetchCustomer(string $id): ?ExternalRecord
    {
        $customer = $this->find('customers', $id);

        return $customer ? $this->customerRecord($customer) : null;
    }

    public function createCustomer(CustomerData $customer): ExternalRecord
    {
        return $this->customerRecord($this->api()->post($this->path('/customers'), $this->customerPayload($customer)));
    }

    public function updateCustomer(string $id, CustomerData $customer): ExternalRecord
    {
        return $this->customerRecord($this->api()->patch($this->record('customers', $id), $this->customerPayload($customer)));
    }

    /** Blocked items stay where they are: nobody should book them on a werkbon. */
    public function pullMaterials(?CarbonImmutable $since = null): iterable
    {
        foreach ($this->api()->pages($this->path('/items'), $this->changedSince($since)) as $item) {
            if (!($item['blocked'] ?? false)) {
                yield $this->materialRecord($item);
            }
        }
    }

    public function fetchMaterial(string $id): ?ExternalRecord
    {
        $item = $this->find('items', $id);

        return $item && !($item['blocked'] ?? false) ? $this->materialRecord($item) : null;
    }

    /**
     * The document first, then its lines one by one, then -- for a final
     * invoice -- posting it. When any of that fails the document is removed
     * again, so a werkbon never leaves half an order behind. Posting is the
     * last step that can fail the send: after it the invoice is booked.
     */
    public function pushSalesDocument(SalesDocumentData $document, DocumentForm $form): PushedDocument
    {
        [$lines, $warnings] = $this->documentLines($document);
        [$list, $lines_list, $date_field] = self::DOCUMENTS[$form->value];

        $header = $this->api()->post($this->path('/' . $list), [
            'customerId' => $document->customer_id,
            'externalDocumentNumber' => mb_substr($document->memo ?: $document->reference, 0, self::MAX_EXTERNAL_NUMBER),
            $date_field => $document->date->toDateString(),
        ]);
        $document_path = $this->record($list, (string) ($header['id'] ?? ''));

        try {
            foreach ($lines as $line) {
                $this->api()->post($document_path . '/' . $lines_list, $line);
            }

            if ($form === DocumentForm::FinalInvoice) {
                $this->api()->post($document_path . '/Microsoft.NAV.post');
            }
        } catch (Throwable $e) {
            throw $this->undoAfter($e, fn () => $this->api()->delete($document_path), 'Het half aangemaakte document');
        }

        [$number, $warnings] = $this->numberOf($form, $document_path, $header, $warnings);

        return new PushedDocument((string) $header['id'], $number, $warnings);
    }

    /**
     * A posted invoice gets its final number only at posting, so it is read
     * again. The invoice is booked by then: when that read fails, the send
     * still succeeded -- reporting it as failed would invite a second press
     * and a second invoice -- and only the number is missing.
     *
     * @param  array<int, string>  $warnings
     * @return array{0: ?string, 1: array<int, string>}
     */
    private function numberOf(DocumentForm $form, string $document_path, array $header, array $warnings): array
    {
        if ($form !== DocumentForm::FinalInvoice) {
            return [$header['number'] ?? null, $warnings];
        }

        try {
            return [$this->api()->get($document_path)['number'] ?? null, $warnings];
        } catch (Refusal $e) {
            return [null, [...$warnings, 'De factuur is geboekt, maar het factuurnummer kon niet worden opgehaald: ' . $e->getMessage()]];
        }
    }

    /**
     * Renewing is the same call as subscribing: an existing subscription is
     * extended, a gone one is made again. Business Central first calls the
     * address with a validation token (see handshake()) before it agrees.
     */
    public function subscribe(Area $area, string $url, IntegrationWebhook $webhook): WebhookSubscription
    {
        $body = [
            'notificationUrl' => $url,
            'resource' => '/api/v2.0' . $this->path('/' . array_search($area, self::RESOURCES, true)),
            'clientState' => $webhook->token,
        ];

        $existing = $webhook->external_id ? $this->api()->find($this->subscriptionPath($webhook)) : null;

        $subscription = $existing
            ? $this->api()->patch($this->subscriptionPath($webhook), $body, $existing['@odata.etag'] ?? '*')
            : $this->api()->post('/subscriptions', $body);

        return new WebhookSubscription(
            $subscription['subscriptionId'] ?? null,
            isset($subscription['expirationDateTime']) ? CarbonImmutable::parse($subscription['expirationDateTime']) : null,
        );
    }

    public function unsubscribe(IntegrationWebhook $webhook): void
    {
        $existing = $webhook->external_id ? $this->api()->find($this->subscriptionPath($webhook)) : null;

        if ($existing) {
            $this->api()->delete($this->subscriptionPath($webhook), $existing['@odata.etag'] ?? '*');
        }
    }

    /** Business Central checks a new address by sending a token it wants back as plain text. */
    public function handshake(Request $request, IntegrationWebhook $webhook): ?Response
    {
        $token = $request->query('validationToken');

        return filled($token) ? response((string) $token, 200, ['Content-Type' => 'text/plain']) : null;
    }

    /**
     * One notice per changed record. A notice that does not carry this
     * webhook's own token as its clientState did not come from the
     * subscription Lavoro made, and is ignored. "collection" means too much
     * changed to list, and pulls what changed in the whole area.
     */
    public function notices(Request $request, IntegrationWebhook $webhook): array
    {
        $notices = [];

        foreach ((array) $request->input('value', []) as $notice) {
            if (!is_array($notice) || !hash_equals($webhook->token, (string) ($notice['clientState'] ?? ''))) {
                continue;
            }

            if (!preg_match('~/(customers|items)(?:\(([0-9a-fA-F-]{36})\))?$~', (string) ($notice['resource'] ?? ''), $match)) {
                continue;
            }

            $collection = ($notice['changeType'] ?? null) === 'collection' || !isset($match[2]);
            $notices[] = new ChangeNotice(self::RESOURCES[$match[1]], $collection ? null : $match[2]);
        }

        return $notices;
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<int, string>} */
    private function documentLines(SalesDocumentData $document): array
    {
        $lines = [];
        $warnings = [];

        foreach ($document->lines as $line) {
            if (!$line->article_id) {
                $warnings[] = 'Materiaal zonder Business Central-artikel overgeslagen: ' . $line->description;

                continue;
            }

            $lines[] = $this->documentLine($line);
        }

        if ($lines === []) {
            throw new Refusal('Geen materialen met een Business Central-artikel op deze werkbon. '
                . 'Haal de artikelen op uit Business Central en gebruik die op de werkbon.');
        }

        return [$lines, $warnings];
    }

    /** @return array<string, mixed> */
    private function documentLine(SalesLineData $line): array
    {
        return [
            'lineType' => 'Item',
            'itemId' => $line->article_id,
            'description' => mb_substr($line->description, 0, self::MAX_LINE_DESCRIPTION),
            'quantity' => $line->quantity,
            'unitPrice' => $line->unit_price,
        ];
    }

    /** @return array<string, string> Empty rather than null: Business Central refuses null for a text field. */
    private function customerPayload(CustomerData $customer): array
    {
        $payload = [];

        foreach (self::CUSTOMER as $field => $theirs) {
            $payload[$theirs] = (string) $customer->{$field};
        }

        return $payload;
    }

    private function customerRecord(array $customer): ExternalRecord
    {
        $values = [];

        foreach (self::CUSTOMER as $field => $theirs) {
            $values[$field] = $customer[$theirs] ?? null;
        }

        return new ExternalRecord((string) $customer['id'], CustomerData::from($values));
    }

    private function materialRecord(array $item): ExternalRecord
    {
        return new ExternalRecord((string) $item['id'], MaterialData::from([
            'name' => $item['displayName'] ?? null,
            'code' => $item['number'] ?? null,
            'price' => $item['unitPrice'] ?? null,
            'cost_price' => $item['unitCost'] ?? null,
            'stock' => $item['inventory'] ?? null,
        ]));
    }

    /** @return array<string, string> */
    private function changedSince(?CarbonImmutable $since): array
    {
        return $since ? ['$filter' => 'lastModifiedDateTime gt ' . $since->utc()->format('Y-m-d\TH:i:s\Z')] : [];
    }

    private function subscriptionPath(IntegrationWebhook $webhook): string
    {
        return "/subscriptions('" . $webhook->external_id . "')";
    }

    /** One record, or null when Business Central does not have it -- or the id could never be one of its. */
    private function find(string $list, string $id): ?array
    {
        return Str::isUuid($id) ? $this->api()->find($this->record($list, $id)) : null;
    }

    /**
     * The path of one record. Ids go into the address as they are, so only a
     * GUID -- the only kind Business Central hands out -- gets that far.
     */
    private function record(string $list, string $id): string
    {
        if (!Str::isUuid($id)) {
            throw new RecordNotFound('Business Central kent geen ' . $list . ' met ID "' . $id . '".');
        }

        return $this->path('/' . $list . '(' . $id . ')');
    }

    private function path(string $path): string
    {
        $company = $this->option('company_id')
            ?? throw new Refusal('Kies eerst het bedrijf in Business Central onder Instellingen → Koppelingen.');

        return BusinessCentralApi::company($company, $path);
    }

    private function api(): BusinessCentralApi
    {
        if ($this->api) {
            return $this->api;
        }

        $tenant = $this->credential('tenant_id');
        $client = $this->credential('client_id');
        $secret = $this->credential('client_secret');

        if (!$tenant || !$client || !$secret) {
            throw new Refusal('Business Central is niet ingesteld: de aanmeldgegevens ontbreken. Vul ze in onder Instellingen → Koppelingen.');
        }

        return $this->api = new BusinessCentralApi($tenant, $client, $secret, (string) $this->option('environment'));
    }
}

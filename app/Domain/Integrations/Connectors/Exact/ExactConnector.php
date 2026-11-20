<?php

namespace App\Domain\Integrations\Connectors\Exact;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\ChangeDetection;
use App\Domain\Integrations\Connector;
use App\Domain\Integrations\Contracts\ConnectsWithOAuth;
use App\Domain\Integrations\Contracts\PullsCustomers;
use App\Domain\Integrations\Contracts\PullsLedgerAccounts;
use App\Domain\Integrations\Contracts\PullsMaterials;
use App\Domain\Integrations\Contracts\PushesCustomers;
use App\Domain\Integrations\Contracts\PushesLedgerAccounts;
use App\Domain\Integrations\Contracts\ReceivesSalesDocuments;
use App\Domain\Integrations\Contracts\ReceivesWebhooks;
use App\Domain\Integrations\Data\ChangeNotice;
use App\Domain\Integrations\Data\CustomerData;
use App\Domain\Integrations\Data\ExternalRecord;
use App\Domain\Integrations\Data\Field;
use App\Domain\Integrations\Data\LedgerAccountData;
use App\Domain\Integrations\Data\MaterialData;
use App\Domain\Integrations\Data\PushedDocument;
use App\Domain\Integrations\Data\SalesDocumentData;
use App\Domain\Integrations\Data\SalesLineData;
use App\Domain\Integrations\Data\WebhookSubscription;
use App\Domain\Integrations\DocumentForm;
use App\Domain\Integrations\OAuth\OAuthTokens;
use App\Exceptions\Refusal;
use App\Models\IntegrationWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Exact Online (NL). Customers and revenue accounts both ways, articles in, and
 * a werkbon out as a sales order, a draft invoice or a processed invoice. Exact
 * can say what changed since a moment, and calls Lavoro itself when customers
 * or articles change.
 *
 * Everything is per administration ("division"); the one the person was in
 * when connecting is taken, and can be changed under the options. Exact allows
 * an app about 60 calls a minute and 5,000 a day per administration, which is
 * why full pulls go through the bulk endpoints, a thousand records a call.
 */
class ExactConnector extends Connector implements ConnectsWithOAuth, PullsCustomers, PullsLedgerAccounts, PullsMaterials, PushesCustomers, PushesLedgerAccounts, ReceivesSalesDocuments, ReceivesWebhooks
{
    /** A customer's field here => the account's field there. */
    private const ACCOUNT = [
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'website' => 'Website',
        'address' => 'AddressLine1',
        'postal_code' => 'Postcode',
        'city' => 'City',
        'country' => 'Country',
        'vat_number' => 'VATNumber',
        'chamber_of_commerce_number' => 'ChamberOfCommerce',
    ];

    private const ITEM_FIELDS = 'ID,Code,Description,StandardSalesPrice,CostPriceStandard,Stock,IsSalesItem,EndDate';

    private const GL_ACCOUNT_FIELDS = 'ID,Code,Description,Type,IsBlocked';

    /** Type 110 is a revenue account ("Omzet"), on the profit and loss account (W) and credited (C). */
    private const REVENUE = ['Type' => 110, 'BalanceType' => 'W', 'BalanceSide' => 'C'];

    /** The webhook topic per area. */
    private const TOPICS = ['Accounts' => Area::Customers, 'Items' => Area::Materials];

    /** Status "C": the account is a customer, not a prospect or a supplier only. */
    private const CUSTOMER = 'C';

    private ?ExactApi $api = null;

    public static function key(): string
    {
        return 'exactonline';
    }

    public static function label(): string
    {
        return 'Exact Online';
    }

    public static function description(): string
    {
        return 'Boekhouding. Klanten en omzetrekeningen twee kanten op, artikelen naar Lavoro, en een werkbon als verkooporder of factuur. Meldt wijzigingen zelf.';
    }

    public static function credentialFields(): array
    {
        return [];
    }

    public static function optionFields(): array
    {
        return [
            new Field('division', 'Administratie (nummer)', required: false, rules: ['integer'], scope: true,
                help: 'Wordt bij het koppelen ingevuld met de administratie waarin je zat. Vul een ander nummer in voor een andere.'),
            new Field('sales_journal', 'Verkoopdagboek', default: '70', rules: ['regex:/^[A-Za-z0-9]+$/'],
                help: 'De code van het verkoopdagboek voor facturen van werkbonnen.'),
        ];
    }

    public static function customerFields(): array
    {
        return array_keys(self::ACCOUNT);
    }

    public static function materialFields(): array
    {
        return ['name', 'code', 'price', 'cost_price', 'stock'];
    }

    public static function customerChanges(): ChangeDetection
    {
        return ChangeDetection::Delta;
    }

    public static function materialChanges(): ChangeDetection
    {
        return ChangeDetection::Delta;
    }

    public static function ledgerAccountChanges(): ChangeDetection
    {
        return ChangeDetection::Delta;
    }

    public static function documentForms(): array
    {
        return [DocumentForm::SalesOrder, DocumentForm::DraftInvoice, DocumentForm::FinalInvoice];
    }

    /** Only with the app's webhook secret: without it a notice cannot be checked, and none is asked for. */
    public static function webhookAreas(): array
    {
        return filled(config('services.exact.webhook_secret')) ? array_values(self::TOPICS) : [];
    }

    public function webhookScope(): string
    {
        return (string) $this->option('division');
    }

    public static function oauthReady(): bool
    {
        return ExactApi::oauth()->ready();
    }

    public function authorizationUrl(string $redirect_uri, string $state): string
    {
        return ExactApi::oauth()->authorizationUrl($redirect_uri, $state);
    }

    /** Takes the administration the person was in, unless one was chosen already. */
    public function completeAuthorization(string $code, string $redirect_uri): string
    {
        (new OAuthTokens($this->connection))->fill(ExactApi::oauth()->exchangeCode($code, $redirect_uri));

        $division = $this->option('division')
            ?? (string) ($this->api()->find('/current/Me', ['$select' => 'CurrentDivision'])['CurrentDivision'] ?? '');

        if ($division === '') {
            throw new Refusal('Exact Online noemde geen administratie. Vul het nummer van de administratie in en koppel opnieuw.');
        }

        $this->connection->setOption('division', $division);

        return 'Administratie ' . $division . '.';
    }

    public function test(): void
    {
        $this->api()->get($this->path('/crm/Accounts'), ['$select' => 'ID', '$top' => 1]);
    }

    public function pullCustomers(?CarbonImmutable $since = null): iterable
    {
        $customers = "Status eq '" . self::CUSTOMER . "'";

        $accounts = $since
            ? $this->api()->pages($this->path('/crm/Accounts'), ['$select' => $this->accountFields(), '$filter' => $customers . ' and ' . $this->changedSince($since)])
            : $this->api()->pages($this->path('/bulk/CRM/Accounts'), ['$select' => $this->accountFields(), '$filter' => $customers]);

        foreach ($accounts as $account) {
            yield $this->customerRecord($account);
        }
    }

    public function fetchCustomer(string $id): ?ExternalRecord
    {
        $account = Str::isUuid($id) ? $this->api()->find($this->record('/crm/Accounts', $id), ['$select' => $this->accountFields()]) : null;

        return $account ? $this->customerRecord($account) : null;
    }

    public function createCustomer(CustomerData $customer): ExternalRecord
    {
        $account = $this->api()->post($this->path('/crm/Accounts'), [...$this->accountPayload($customer), 'Status' => self::CUSTOMER]);

        return $this->customerRecord($account);
    }

    /** Exact answers an update without the record, so it is read again: the next pull compares against what Exact keeps. */
    public function updateCustomer(string $id, CustomerData $customer): ExternalRecord
    {
        $this->api()->put($this->record('/crm/Accounts', $id), $this->accountPayload($customer));

        return $this->fetchCustomer($id) ?? throw new Refusal('Exact Online gaf de bijgewerkte klant niet terug.');
    }

    /** Only articles that can be sold and have not ended: nobody should book another on a werkbon. */
    public function pullMaterials(?CarbonImmutable $since = null): iterable
    {
        $items = $since
            ? $this->api()->pages($this->path('/logistics/Items'), ['$select' => self::ITEM_FIELDS, '$filter' => $this->changedSince($since)])
            : $this->api()->pages($this->path('/bulk/Logistics/Items'), ['$select' => self::ITEM_FIELDS]);

        foreach ($items as $item) {
            if ($this->sellable($item)) {
                yield $this->materialRecord($item);
            }
        }
    }

    public function fetchMaterial(string $id): ?ExternalRecord
    {
        $item = Str::isUuid($id) ? $this->api()->find($this->record('/logistics/Items', $id), ['$select' => self::ITEM_FIELDS]) : null;

        return $item && $this->sellable($item) ? $this->materialRecord($item) : null;
    }

    /** Only revenue accounts that are not blocked: those are what a sales line can be booked on. */
    public function pullLedgerAccounts(?CarbonImmutable $since = null): iterable
    {
        $revenue = 'Type eq ' . self::REVENUE['Type'];

        $accounts = $since
            ? $this->api()->pages($this->path('/financial/GLAccounts'), ['$select' => self::GL_ACCOUNT_FIELDS, '$filter' => $revenue . ' and ' . $this->changedSince($since)])
            : $this->api()->pages($this->path('/bulk/Financial/GLAccounts'), ['$select' => self::GL_ACCOUNT_FIELDS, '$filter' => $revenue]);

        foreach ($accounts as $account) {
            if ($this->bookable($account)) {
                yield $this->ledgerAccountRecord($account);
            }
        }
    }

    public function fetchLedgerAccount(string $id): ?ExternalRecord
    {
        $account = Str::isUuid($id) ? $this->api()->find($this->record('/financial/GLAccounts', $id), ['$select' => self::GL_ACCOUNT_FIELDS]) : null;

        return $account && $this->bookable($account) ? $this->ledgerAccountRecord($account) : null;
    }

    public function createLedgerAccount(LedgerAccountData $account): ExternalRecord
    {
        return $this->ledgerAccountRecord($this->api()->post($this->path('/financial/GLAccounts'), [
            ...$this->ledgerAccountPayload($account),
            ...self::REVENUE,
        ]));
    }

    /** Read back as it is, blocked or not: it was just found to update. */
    public function updateLedgerAccount(string $id, LedgerAccountData $account): ExternalRecord
    {
        $path = $this->record('/financial/GLAccounts', $id);
        $this->api()->put($path, $this->ledgerAccountPayload($account));

        $updated = $this->api()->find($path, ['$select' => self::GL_ACCOUNT_FIELDS])
            ?? throw new Refusal('Exact Online gaf de bijgewerkte grootboekrekening niet terug.');

        return $this->ledgerAccountRecord($updated);
    }

    /**
     * The document with its lines in one call, so Exact either has all of it
     * or none. A final invoice is then processed ("printed", without mailing
     * the customer); when that fails, the draft is removed again so it is not
     * left half done.
     */
    public function pushSalesDocument(SalesDocumentData $document, DocumentForm $form): PushedDocument
    {
        [$lines, $warnings] = $this->documentLines($document, invoice: $form !== DocumentForm::SalesOrder);

        if ($form === DocumentForm::SalesOrder) {
            $order = $this->api()->post($this->path('/salesorder/SalesOrders'), [
                ...$this->documentHeader($document),
                'OrderDate' => $document->date->toDateString(),
                'SalesOrderLines' => $lines,
            ]);

            return new PushedDocument((string) $order['OrderID'], isset($order['OrderNumber']) ? (string) $order['OrderNumber'] : null, $warnings);
        }

        $invoice = $this->api()->post($this->path('/salesinvoice/SalesInvoices'), [
            ...$this->documentHeader($document),
            'Journal' => $this->option('sales_journal'),
            'InvoiceDate' => $document->date->toDateString(),
            'SalesInvoiceLines' => $lines,
        ]);
        $id = (string) $invoice['InvoiceID'];

        if ($form === DocumentForm::FinalInvoice) {
            $this->process($id);
            [$number, $warnings] = $this->processedNumber($id, $warnings);

            return new PushedDocument($id, $number, $warnings);
        }

        return new PushedDocument($id, isset($invoice['InvoiceNumber']) ? (string) $invoice['InvoiceNumber'] : null, $warnings);
    }

    /** Exact's subscriptions do not run out: one is made once per administration and topic. */
    public function subscribe(Area $area, string $url, IntegrationWebhook $webhook): WebhookSubscription
    {
        $subscription = $this->api()->post($this->path('/webhooks/WebhookSubscriptions'), [
            'CallbackURL' => $url,
            'Topic' => array_search($area, self::TOPICS, true),
        ]);

        return new WebhookSubscription(isset($subscription['ID']) ? (string) $subscription['ID'] : null, null);
    }

    public function unsubscribe(IntegrationWebhook $webhook): void
    {
        if (Str::isUuid((string) $webhook->external_id)) {
            $this->api()->delete($this->record('/webhooks/WebhookSubscriptions', $webhook->external_id));
        }
    }

    /** Exact checks a new address by calling it with an empty body, and wants a plain 200 back. */
    public function handshake(Request $request, IntegrationWebhook $webhook): ?Response
    {
        return $request->getContent() === '' ? response('', 200) : null;
    }

    /**
     * Exact signs the "Content" part of a notice with the app's webhook
     * secret: HMAC-SHA256 over that part exactly as sent, in upper-case hex,
     * in "HashCode". A notice that does not add up, or is about another
     * administration, is ignored.
     */
    public function notices(Request $request, IntegrationWebhook $webhook): array
    {
        $body = $request->getContent();
        $secret = (string) config('services.exact.webhook_secret');

        if ($secret === '' || !preg_match('/"Content"\s*:\s*(\{.*?\})\s*,\s*"HashCode"\s*:\s*"([0-9A-Fa-f]+)"/s', $body, $match)) {
            return [];
        }

        if (!hash_equals(strtoupper(hash_hmac('sha256', $match[1], $secret)), strtoupper($match[2]))) {
            return [];
        }

        $content = json_decode($match[1], true) ?? [];
        $area = self::TOPICS[$content['Topic'] ?? ''] ?? null;

        if (!$area || (string) ($content['Division'] ?? '') !== (string) $this->option('division') || !Str::isUuid((string) ($content['Key'] ?? ''))) {
            return [];
        }

        return [new ChangeNotice($area, (string) $content['Key'])];
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<int, string>} */
    private function documentLines(SalesDocumentData $document, bool $invoice): array
    {
        $lines = [];
        $warnings = [];

        foreach ($document->lines as $line) {
            if (!$line->article_id) {
                $warnings[] = 'Materiaal zonder artikel in Exact Online overgeslagen: ' . $line->description;

                continue;
            }

            $lines[] = $this->documentLine($line, $invoice);
        }

        if ($lines === []) {
            throw new Refusal('Geen materialen met een artikel in Exact Online op deze werkbon. '
                . 'Haal de artikelen op uit Exact Online en gebruik die op de werkbon.');
        }

        return [$lines, $warnings];
    }

    /**
     * An invoice line goes onto the material's revenue account, when it has
     * one there, instead of the article's own. A sales order line has no such
     * field: the invoice Exact later makes of it takes the article's.
     *
     * @return array<string, mixed>
     */
    private function documentLine(SalesLineData $line, bool $invoice): array
    {
        return array_filter([
            'Item' => $line->article_id,
            'Description' => mb_substr($line->description, 0, 60),
            'Quantity' => $line->quantity,
            'UnitPrice' => $line->unit_price,
            'GLAccount' => $invoice ? $line->ledger_account_id : null,
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    private function documentHeader(SalesDocumentData $document): array
    {
        return [
            'OrderedBy' => $document->customer_id,
            'Description' => mb_substr($document->description, 0, 60),
            'YourRef' => mb_substr($document->memo ?: $document->reference, 0, 30),
        ];
    }

    private function process(string $invoice_id): void
    {
        try {
            $this->api()->post($this->path('/salesinvoice/PrintedSalesInvoices'), ['InvoiceID' => $invoice_id, 'SendEmailToCustomer' => false]);
        } catch (Throwable $e) {
            throw $this->undoAfter($e, fn () => $this->api()->delete($this->record('/salesinvoice/SalesInvoices', $invoice_id)), 'De conceptfactuur');
        }
    }

    /**
     * The number a processed invoice ended up with. It is booked by then: a
     * failed read is a warning, not a failed send, or the next press would
     * book a second invoice.
     *
     * @param  array<int, string>  $warnings
     * @return array{0: ?string, 1: array<int, string>}
     */
    private function processedNumber(string $invoice_id, array $warnings): array
    {
        try {
            $invoice = $this->api()->find($this->record('/salesinvoice/SalesInvoices', $invoice_id), ['$select' => 'InvoiceNumber']);

            return [isset($invoice['InvoiceNumber']) ? (string) $invoice['InvoiceNumber'] : null, $warnings];
        } catch (Refusal $e) {
            return [null, [...$warnings, 'De factuur is verwerkt, maar het factuurnummer kon niet worden opgehaald: ' . $e->getMessage()]];
        }
    }

    /** @return array<string, string> Empty rather than null: Exact keeps a text field empty, not missing. */
    private function accountPayload(CustomerData $customer): array
    {
        $payload = [];

        foreach (self::ACCOUNT as $field => $theirs) {
            $payload[$theirs] = (string) $customer->{$field};
        }

        return $payload;
    }

    private function customerRecord(array $account): ExternalRecord
    {
        $values = [];

        foreach (self::ACCOUNT as $field => $theirs) {
            $values[$field] = $account[$theirs] ?? null;
        }

        return new ExternalRecord((string) $account['ID'], CustomerData::from($values));
    }

    private function materialRecord(array $item): ExternalRecord
    {
        return new ExternalRecord((string) $item['ID'], MaterialData::from([
            'name' => $item['Description'] ?? null,
            'code' => isset($item['Code']) ? trim((string) $item['Code']) : null,
            'price' => $item['StandardSalesPrice'] ?? null,
            'cost_price' => $item['CostPriceStandard'] ?? null,
            'stock' => $item['Stock'] ?? null,
        ]));
    }

    private function ledgerAccountRecord(array $account): ExternalRecord
    {
        return new ExternalRecord((string) $account['ID'], LedgerAccountData::from([
            'name' => $account['Description'] ?? null,
            'code' => isset($account['Code']) ? trim((string) $account['Code']) : null,
        ]));
    }

    /** @return array<string, string> Exact requires a number and a description on every account. */
    private function ledgerAccountPayload(LedgerAccountData $account): array
    {
        if (!filled($account->code)) {
            throw new Refusal('Grootboekrekening "' . $account->name . '" heeft geen nummer, en Exact Online vereist er een.');
        }

        return ['Code' => $account->code, 'Description' => (string) $account->name];
    }

    private function bookable(array $account): bool
    {
        return (int) ($account['Type'] ?? 0) === self::REVENUE['Type'] && !($account['IsBlocked'] ?? false);
    }

    private function sellable(array $item): bool
    {
        if (!($item['IsSalesItem'] ?? true)) {
            return false;
        }

        $end = self::date($item['EndDate'] ?? null);

        return $end === null || $end->isFuture();
    }

    /** Exact writes a date as "/Date(1700000000000)/", milliseconds since 1970. */
    private static function date(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value) || !preg_match('/\/Date\((-?\d+)/', $value, $match)) {
            return null;
        }

        return CarbonImmutable::createFromTimestampMs((int) $match[1]);
    }

    /**
     * Exact does not say in which time zone it reads this filter, so the
     * moment goes an hour earlier still: a few records more is harmless, one
     * missed is not.
     */
    private function changedSince(CarbonImmutable $since): string
    {
        return "Modified gt datetime'" . $since->utc()->subHour()->format('Y-m-d\TH:i:s') . "'";
    }

    private function accountFields(): string
    {
        return 'ID,' . implode(',', self::ACCOUNT);
    }

    private function record(string $list, string $id): string
    {
        if (!Str::isUuid($id)) {
            throw new Refusal('Exact Online kent geen record met ID "' . $id . '".');
        }

        return $this->path($list . "(guid'" . $id . "')");
    }

    private function path(string $path): string
    {
        $division = $this->option('division');

        if (!$division || !ctype_digit($division)) {
            throw new Refusal('Exact Online is nog niet gekoppeld aan een administratie. Koppel opnieuw onder Instellingen → Koppelingen.');
        }

        return '/' . $division . $path;
    }

    private function api(): ExactApi
    {
        return $this->api ??= new ExactApi($this->connection);
    }
}

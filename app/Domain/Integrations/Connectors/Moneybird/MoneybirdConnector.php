<?php

namespace App\Domain\Integrations\Connectors\Moneybird;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\ChangeDetection;
use App\Domain\Integrations\Connector;
use App\Domain\Integrations\Contracts\ConnectsWithOAuth;
use App\Domain\Integrations\Contracts\PullsCustomers;
use App\Domain\Integrations\Contracts\PullsLedgerAccounts;
use App\Domain\Integrations\Contracts\PullsMaterials;
use App\Domain\Integrations\Contracts\PushesCustomers;
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
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Moneybird. Contacts both ways, products in as materials, revenue accounts
 * in, and a werkbon out as an invoice: left as a draft, or sent -- without
 * mailing the customer -- so it gets its number and is booked. Moneybird can list the contacts changed
 * since a moment and calls Lavoro itself when contacts or products change.
 *
 * Moneybird signs every notice with a secret it hands out once, when the
 * webhook is made; that secret is kept on the webhook.
 *
 * Accounts are not sent: Moneybird wants an RGS code with every new one,
 * which Lavoro does not know.
 */
class MoneybirdConnector extends Connector implements ConnectsWithOAuth, PullsCustomers, PullsLedgerAccounts, PullsMaterials, PushesCustomers, ReceivesSalesDocuments, ReceivesWebhooks
{
    /** A customer's field here => the contact's field there. The name is handled apart: a contact may be a person. */
    private const CONTACT = [
        'contactname' => 'attention',
        'email' => 'email',
        'invoice_email' => 'send_invoices_to_email',
        'quotes_email' => 'send_estimates_to_email',
        'phone' => 'phone',
        'address' => 'address1',
        'postal_code' => 'zipcode',
        'city' => 'city',
        'country' => 'country',
        'vat_number' => 'tax_number',
        'chamber_of_commerce_number' => 'chamber_of_commerce',
        'iban' => 'bank_account',
    ];

    /** The events of one webhook, per area. */
    private const EVENTS = [
        'customers' => ['contact_created', 'contact_changed', 'contact_destroyed', 'contact_archived', 'contact_activated'],
        'materials' => ['product_created', 'product_updated', 'product_destroyed', 'product_activated', 'product_deactivated'],
    ];

    /** How far a notice's signing time may lie from now, as Moneybird advises. */
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    private ?MoneybirdApi $api = null;

    /** @var array<string, string>|null Sales tax rates by percentage, read once per send. */
    private ?array $tax_rates = null;

    public static function key(): string
    {
        return 'moneybird';
    }

    public static function label(): string
    {
        return 'Moneybird';
    }

    public static function description(): string
    {
        return 'Boekhouding. Contacten twee kanten op, producten en omzetrekeningen naar Lavoro, en een werkbon als factuur. Meldt wijzigingen zelf.';
    }

    public static function credentialFields(): array
    {
        return [];
    }

    public static function optionFields(): array
    {
        return [
            new Field('administration_id', 'Administratie (ID)', required: false, rules: ['regex:/^\d+$/'], scope: true,
                help: 'Wordt bij het koppelen ingevuld. Heb je er meer, dan noemt de melding na het koppelen ze allemaal.'),
        ];
    }

    public static function customerFields(): array
    {
        return ['name', ...array_keys(self::CONTACT)];
    }

    public static function materialFields(): array
    {
        return ['name', 'code', 'price'];
    }

    public static function customerChanges(): ChangeDetection
    {
        return ChangeDetection::Delta;
    }

    /** Products cannot be asked for "changed since"; there are seldom many. */
    public static function materialChanges(): ChangeDetection
    {
        return ChangeDetection::Full;
    }

    /** Moneybird hands over all accounts in one answer, without pages or a "changed since". */
    public static function ledgerAccountChanges(): ChangeDetection
    {
        return ChangeDetection::Full;
    }

    public static function documentForms(): array
    {
        return [DocumentForm::DraftInvoice, DocumentForm::FinalInvoice];
    }

    public static function webhookAreas(): array
    {
        return [Area::Customers, Area::Materials];
    }

    public function webhookScope(): string
    {
        return (string) $this->option('administration_id');
    }

    public static function oauthReady(): bool
    {
        return MoneybirdApi::oauth()->ready();
    }

    public function authorizationUrl(string $redirect_uri, string $state): string
    {
        return MoneybirdApi::oauth()->authorizationUrl($redirect_uri, $state);
    }

    /**
     * Takes the administration already chosen -- refusing when this login
     * cannot see it, rather than quietly linking to another one -- or else the
     * first this login may see. With more than one, the message names them
     * all, so the right id can be filled in.
     */
    public function completeAuthorization(string $code, string $redirect_uri): string
    {
        (new OAuthTokens($this->connection))->fill(MoneybirdApi::oauth()->exchangeCode($code, $redirect_uri));

        $administrations = $this->api()->administrations();
        $chosen = $this->option('administration_id');

        if ($administrations === []) {
            throw new Refusal('Deze Moneybird-gebruiker heeft geen administratie.');
        }

        if ($chosen && !collect($administrations)->contains('id', $chosen)) {
            throw new Refusal('Deze Moneybird-gebruiker kan administratie ' . $chosen . ' niet zien. Log in als een gebruiker die dat wel kan.');
        }

        $administration = collect($administrations)->firstWhere('id', $chosen) ?? $administrations[0];
        $this->connection->setOption('administration_id', $administration['id']);

        if (count($administrations) === 1) {
            return 'Administratie ' . $administration['name'] . '.';
        }

        return 'Administratie ' . $administration['name'] . '. Een andere kiezen kan onder de instellingen van de koppeling: '
            . collect($administrations)->map(fn (array $one) => $one['name'] . ' (' . $one['id'] . ')')->implode(', ') . '.';
    }

    public function test(): void
    {
        $this->api()->get('/contacts.json', ['per_page' => 1]);
    }

    public function pullCustomers(?CarbonImmutable $since = null): iterable
    {
        $contacts = $since
            ? $this->api()->pages('/contacts/filter.json', ['filter' => 'updated_after:' . $since->utc()->toIso8601ZuluString()])
            : $this->api()->pages('/contacts.json');

        foreach ($contacts as $contact) {
            yield $this->customerRecord($contact);
        }
    }

    public function fetchCustomer(string $id): ?ExternalRecord
    {
        $contact = ctype_digit($id) ? $this->api()->find('/contacts/' . $id . '.json') : null;

        return $contact ? $this->customerRecord($contact) : null;
    }

    public function createCustomer(CustomerData $customer): ExternalRecord
    {
        return $this->customerRecord($this->api()->post('/contacts.json', ['contact' => $this->contactPayload($customer)]));
    }

    public function updateCustomer(string $id, CustomerData $customer): ExternalRecord
    {
        return $this->customerRecord($this->api()->patch('/contacts/' . $this->id($id) . '.json', ['contact' => $this->contactPayload($customer)]));
    }

    public function pullMaterials(?CarbonImmutable $since = null): iterable
    {
        foreach ($this->api()->pages('/products.json') as $product) {
            yield $this->materialRecord($product);
        }
    }

    public function fetchMaterial(string $id): ?ExternalRecord
    {
        $product = ctype_digit($id) ? $this->api()->find('/products/' . $id . '.json') : null;

        return $product ? $this->materialRecord($product) : null;
    }

    public function pullLedgerAccounts(?CarbonImmutable $since = null): iterable
    {
        foreach ($this->api()->get('/ledger_accounts.json') as $account) {
            if ($this->bookable($account)) {
                yield $this->ledgerAccountRecord($account);
            }
        }
    }

    public function fetchLedgerAccount(string $id): ?ExternalRecord
    {
        $account = ctype_digit($id) ? $this->api()->find('/ledger_accounts/' . $id . '.json') : null;

        return $account && $this->bookable($account) ? $this->ledgerAccountRecord($account) : null;
    }

    /**
     * The invoice as a draft, with prices excluding VAT and Moneybird's own
     * sales tax rate per line. A final invoice is then sent with the delivery
     * method "Manual": Moneybird gives it its number and books it, without
     * mailing anyone. When that fails, the draft is removed again.
     */
    public function pushSalesDocument(SalesDocumentData $document, DocumentForm $form): PushedDocument
    {
        if ($document->lines === []) {
            throw new Refusal('Er staat geen regel op deze werkbon die gefactureerd kan worden.');
        }

        $invoice = $this->api()->post('/sales_invoices.json', ['sales_invoice' => [
            'contact_id' => $this->id($document->customer_id),
            'reference' => trim($document->reference . ($document->memo ? ' (' . $document->memo . ')' : '')),
            'invoice_date' => $document->date->toDateString(),
            'prices_are_incl_tax' => false,
            'details_attributes' => array_map(fn (SalesLineData $line) => $this->invoiceLine($line), $document->lines),
        ]]);
        $id = (string) $invoice['id'];

        if ($form === DocumentForm::DraftInvoice) {
            return new PushedDocument($id);
        }

        try {
            $sent = $this->api()->patch('/sales_invoices/' . $id . '/send_invoice.json', ['sales_invoice_sending' => ['delivery_method' => 'Manual']]);
        } catch (Throwable $e) {
            throw $this->undoAfter($e, fn () => $this->api()->delete('/sales_invoices/' . $id . '.json'), 'De conceptfactuur');
        }

        $number = $sent['invoice_id'] ?? null;

        return new PushedDocument($id, $number ? (string) $number : null, $number ? [] : ['De factuur is verzonden, maar Moneybird gaf geen factuurnummer terug.']);
    }

    /** Moneybird's webhooks do not run out: one per area, made once. */
    public function subscribe(Area $area, string $url, IntegrationWebhook $webhook): WebhookSubscription
    {
        $made = $this->api()->post('/webhooks.json', ['url' => $url, 'enabled_events' => self::EVENTS[$area->value]]);

        return new WebhookSubscription(isset($made['id']) ? (string) $made['id'] : null, null, $made['secret'] ?? null);
    }

    public function unsubscribe(IntegrationWebhook $webhook): void
    {
        if (ctype_digit((string) $webhook->external_id)) {
            $this->api()->delete('/webhooks/' . $webhook->external_id . '.json');
        }
    }

    public function handshake(Request $request, IntegrationWebhook $webhook): ?Response
    {
        return null;
    }

    /**
     * Only a notice signed with this webhook's secret, at most five minutes
     * old, and about this administration counts. The signature is
     * "t=<time>,v1=<hex>", an HMAC-SHA256 over "<time>.<body as sent>"; what
     * is read comes from that same body, never from the address.
     */
    public function notices(Request $request, IntegrationWebhook $webhook): array
    {
        if (!$this->signed($request, (string) $webhook->signing_secret)) {
            return [];
        }

        $notice = json_decode($request->getContent(), true);
        $area = match ($notice['entity_type'] ?? null) {
            'Contact' => Area::Customers,
            'Product' => Area::Materials,
            default => null,
        };
        $id = (string) ($notice['entity_id'] ?? '');

        if (!$area || (string) ($notice['administration_id'] ?? '') !== (string) $this->option('administration_id') || !ctype_digit($id)) {
            return [];
        }

        return [new ChangeNotice($area, $id)];
    }

    private function signed(Request $request, string $secret): bool
    {
        $header = (string) $request->header('Moneybird-Signature');

        if ($secret === '' || !preg_match('/(?:^|,)\s*t=(\d+)/', $header, $time)) {
            return false;
        }

        if (abs(CarbonImmutable::now()->getTimestamp() - (int) $time[1]) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $time[1] . '.' . $request->getContent(), $secret);
        preg_match_all('/(?:^|,)\s*v1=([0-9a-f]+)/i', $header, $signatures);

        return collect($signatures[1])->contains(fn (string $signature) => hash_equals($expected, strtolower($signature)));
    }

    /** @return array<string, mixed> */
    private function invoiceLine(SalesLineData $line): array
    {
        return array_filter([
            'description' => $line->description,
            'price' => number_format($line->unit_price, 2, '.', ''),
            'amount' => rtrim(rtrim(number_format($line->quantity, 4, '.', ''), '0'), '.'),
            'tax_rate_id' => $this->taxRate($line->vat_percent),
            'product_id' => $line->article_id,
            'ledger_account_id' => $line->ledger_account_id,
        ], fn ($value) => $value !== null);
    }

    /** An active revenue account that sales invoices may use: what a sales line can be booked on. */
    private function bookable(array $account): bool
    {
        return ($account['account_type'] ?? null) === 'revenue'
            && ($account['active'] ?? true)
            && in_array('sales_invoice', $account['allowed_document_types'] ?? ['sales_invoice'], true);
    }

    private function ledgerAccountRecord(array $account): ExternalRecord
    {
        return new ExternalRecord((string) $account['id'], LedgerAccountData::from([
            'name' => $account['name'] ?? null,
            'code' => $account['account_id'] ?? null,
        ]));
    }

    private function taxRate(float $percent): string
    {
        $this->tax_rates ??= collect($this->api()->get('/tax_rates.json', ['filter' => 'tax_rate_type:sales_invoice,active:true']))
            ->mapWithKeys(fn (array $rate) => [number_format((float) $rate['percentage'], 2) => (string) $rate['id']])
            ->all();

        return $this->tax_rates[number_format($percent, 2)]
            ?? throw new Refusal('Moneybird heeft geen actief btw-tarief voor verkoopfacturen van ' . rtrim(rtrim(number_format($percent, 2, ',', ''), '0'), ',') . '%.');
    }

    /**
     * A person's contact has no company name; its name is then first and
     * last name. Lavoro's customers are companies, so on the way back the name
     * goes into the company name.
     *
     * @return array<string, string>
     */
    private function contactPayload(CustomerData $customer): array
    {
        $payload = ['company_name' => (string) $customer->name];

        foreach (self::CONTACT as $field => $theirs) {
            $payload[$theirs] = (string) $customer->{$field};
        }

        return $payload;
    }

    private function customerRecord(array $contact): ExternalRecord
    {
        $values = ['name' => filled($contact['company_name'] ?? null)
            ? $contact['company_name']
            : trim(($contact['firstname'] ?? '') . ' ' . ($contact['lastname'] ?? ''))];

        foreach (self::CONTACT as $field => $theirs) {
            $values[$field] = $contact[$theirs] ?? null;
        }

        return new ExternalRecord((string) $contact['id'], CustomerData::from($values));
    }

    private function materialRecord(array $product): ExternalRecord
    {
        return new ExternalRecord((string) $product['id'], MaterialData::from([
            'name' => ($product['title'] ?? null) ?: ($product['description'] ?? null),
            'code' => $product['identifier'] ?? null,
            'price' => $product['price'] ?? null,
        ]));
    }

    /** Moneybird's ids are numbers; anything else never goes into an address. */
    private function id(string $id): string
    {
        return ctype_digit($id) ? $id : throw new Refusal('Moneybird kent geen record met ID "' . $id . '".');
    }

    private function api(): MoneybirdApi
    {
        return $this->api ??= new MoneybirdApi($this->connection);
    }
}

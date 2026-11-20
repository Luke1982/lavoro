<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Connectors\BusinessCentral\BusinessCentralConnector;
use App\Domain\Integrations\Data\CustomerData;
use App\Domain\Integrations\Data\SalesDocumentData;
use App\Domain\Integrations\Data\SalesLineData;
use App\Domain\Integrations\DocumentForm;
use App\Domain\Integrations\IntegrationUnavailable;
use App\Domain\Integrations\Sync\Synchronizer;
use App\Exceptions\Refusal;
use App\Models\Central\IntegrationWebhookLookup;
use App\Models\Customer;
use App\Models\IntegrationConnection;
use App\Models\IntegrationWebhook;
use App\Models\Material;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Business Central behind the standard shapes, against a fake of its API that
 * keeps what was sent: the login, the paging, the documents, the webhooks.
 */
class BusinessCentralConnectorTest extends TestCase
{
    private const TENANT = '11111111-1111-1111-1111-111111111111';

    private const COMPANY = '22222222-2222-2222-2222-222222222222';

    private const ROOT = 'https://api.businesscentral.dynamics.com/v2.0/' . self::TENANT . '/production/api/v2.0';

    private const CUSTOMER_ID = '33333333-3333-3333-3333-333333333333';

    private const ITEM_ID = '44444444-4444-4444-4444-444444444444';

    private const ORDER_ID = '88888888-8888-8888-8888-888888888888';

    private const INVOICE_ID = '99999999-9999-9999-9999-999999999999';

    private IntegrationConnection $connection;

    /** @var array<int, Request> */
    private array $sent = [];

    /** @var array<string, \Closure(Request): mixed> Overrides per "METHOD path", for one test. */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->answer($request));

        $this->connection = new IntegrationConnection([
            'connector' => 'businesscentral',
            'is_active' => true,
            'settings' => [
                ...IntegrationConnection::defaultSettings('businesscentral'),
                'customers' => ['read' => true, 'write' => false, 'leading' => 'external'],
                'options' => ['environment' => 'production', 'company_id' => self::COMPANY],
            ],
        ]);
        $this->connection->storeCredentials(['tenant_id' => self::TENANT, 'client_id' => 'app', 'client_secret' => 'geheim']);
        $this->connection->saveQuietly();
    }

    private function answer(Request $request)
    {
        $this->sent[] = $request;

        if (str_starts_with($request->url(), 'https://login.microsoftonline.com/')) {
            return Http::response(['access_token' => 'token-' . count($this->sent), 'expires_in' => 3599]);
        }

        $path = Str::before(Str::after($request->url(), self::ROOT), '?');
        $key = $request->method() . ' ' . $path;

        if (isset($this->answers[$key])) {
            return ($this->answers[$key])($request);
        }

        $company = '/companies(' . self::COMPANY . ')';

        return match ($key) {
            'GET /companies' => Http::response(['value' => [['id' => self::COMPANY, 'name' => 'CRONUS', 'displayName' => 'CRONUS Nederland']]]),
            'GET ' . $company => Http::response(['id' => self::COMPANY]),
            'GET ' . $company . '/customers' => Http::response(['value' => [$this->customer(self::CUSTOMER_ID, 'Garage Smit')]]),
            'GET ' . $company . '/customers(' . self::CUSTOMER_ID . ')' => Http::response($this->customer(self::CUSTOMER_ID, 'Garage Smit')),
            'PATCH ' . $company . '/customers(' . self::CUSTOMER_ID . ')' => Http::response([...$this->customer(self::CUSTOMER_ID, ''), ...$request->data()]),
            'POST ' . $company . '/customers' => Http::response([...$request->data(), 'id' => '55555555-5555-5555-5555-555555555555'], 201),
            'GET ' . $company . '/items' => Http::response(['value' => [
                ['id' => self::ITEM_ID, 'number' => '1000', 'displayName' => 'Filter', 'unitPrice' => 12.5, 'unitCost' => 4.1, 'inventory' => 7, 'blocked' => false],
                ['id' => '66666666-6666-6666-6666-666666666666', 'number' => '1001', 'displayName' => 'Oud', 'unitPrice' => 0, 'blocked' => true],
            ]]),
            'POST ' . $company . '/salesOrders' => Http::response(['id' => self::ORDER_ID, 'number' => 'VO1001', 'status' => 'Draft'], 201),
            'POST ' . $company . '/salesOrders(' . self::ORDER_ID . ')/salesOrderLines' => Http::response(['id' => 'line'], 201),
            'POST ' . $company . '/salesInvoices' => Http::response(['id' => self::INVOICE_ID, 'number' => 'CONCEPT-7', 'status' => 'Draft'], 201),
            'POST ' . $company . '/salesInvoices(' . self::INVOICE_ID . ')/salesInvoiceLines' => Http::response(['id' => 'line'], 201),
            'POST ' . $company . '/salesInvoices(' . self::INVOICE_ID . ')/Microsoft.NAV.post' => Http::response(null, 204),
            'GET ' . $company . '/salesInvoices(' . self::INVOICE_ID . ')' => Http::response(['id' => self::INVOICE_ID, 'number' => 'VF2026-001', 'status' => 'Open']),
            'DELETE ' . $company . '/salesOrders(' . self::ORDER_ID . ')', 'DELETE ' . $company . '/salesInvoices(' . self::INVOICE_ID . ')' => Http::response(null, 204),
            'POST /subscriptions' => Http::response(['subscriptionId' => 'sub-1', 'expirationDateTime' => '2026-11-22T10:00:00Z'], 201),
            "GET /subscriptions('sub-1')" => Http::response(['subscriptionId' => 'sub-1', '@odata.etag' => 'W/"etag-1"']),
            "PATCH /subscriptions('sub-1')" => Http::response(['subscriptionId' => 'sub-1', 'expirationDateTime' => '2026-11-25T10:00:00Z']),
            "DELETE /subscriptions('sub-1')" => Http::response(null, 204),
        };
    }

    private function customer(string $id, string $name): array
    {
        return [
            'id' => $id, 'number' => 'K' . substr($id, 0, 3), 'displayName' => $name, 'addressLine1' => 'Kerkweg 3',
            'postalCode' => '1234 AB', 'city' => 'Ede', 'country' => 'NL', 'email' => 'info@smit.nl',
            'taxRegistrationNumber' => 'NL001', 'lastModifiedDateTime' => '2026-11-19T08:00:00Z',
        ];
    }

    private function connector(): BusinessCentralConnector
    {
        return $this->connection->connector();
    }

    /** @return array<int, Request> */
    private function sentTo(string $method, string $path): array
    {
        return array_values(array_filter($this->sent, fn (Request $request) => $request->method() === $method
            && Str::before(Str::after($request->url(), self::ROOT), '?') === $path));
    }

    private function document(array $lines): SalesDocumentData
    {
        return new SalesDocumentData('Werkbon 12', 'Werkbon 12', CarbonImmutable::parse('2026-11-19'), self::CUSTOMER_ID,
            CustomerData::from(['name' => 'Garage Smit']), $lines);
    }

    private function companyPath(string $path = ''): string
    {
        return '/companies(' . self::COMPANY . ')' . $path;
    }

    public function test_it_logs_in_as_the_app_of_the_customers_own_tenant_once(): void
    {
        $this->connector()->test();
        $this->connection->connector()->test();

        $logins = array_values(array_filter($this->sent, fn (Request $request) => str_contains($request->url(), 'login.microsoftonline.com')));
        $this->assertCount(1, $logins, 'the token is reused');
        $this->assertStringContainsString('/' . self::TENANT . '/oauth2/v2.0/token', $logins[0]->url());
        $this->assertSame('client_credentials', $logins[0]['grant_type']);
        $this->assertSame('https://api.businesscentral.dynamics.com/.default', $logins[0]['scope']);
    }

    public function test_without_a_company_the_test_names_the_companies_there_are(): void
    {
        $this->connection->forceFill(['settings' => [...$this->connection->settings, 'options' => ['environment' => 'production']]])->saveQuietly();

        $this->expectException(Refusal::class);
        $this->expectExceptionMessage('CRONUS Nederland (' . self::COMPANY . ')');

        $this->connection->fresh()->connector()->test();
    }

    public function test_customers_come_in_page_by_page(): void
    {
        $this->answers['GET ' . $this->companyPath('/customers')] = fn (Request $request) => str_contains($request->url(), 'skiptoken')
            ? Http::response(['value' => [$this->customer('77777777-7777-7777-7777-777777777777', 'Bakkerij Jansen')]])
            : Http::response(['value' => [$this->customer(self::CUSTOMER_ID, 'Garage Smit')], '@odata.nextLink' => self::ROOT . $this->companyPath('/customers') . '?$skiptoken=2']);

        app(Synchronizer::class)->pull($this->connection, Area::Customers);

        $this->assertSame(2, Customer::whereIn('name', ['Garage Smit', 'Bakkerij Jansen'])->count());
        $this->assertSame('NL001', Customer::where('name', 'Garage Smit')->value('vat_number'));
    }

    public function test_a_next_page_somewhere_else_is_not_followed(): void
    {
        $this->answers['GET ' . $this->companyPath('/customers')] = fn () => Http::response([
            'value' => [], '@odata.nextLink' => 'https://elders.example/stuur-je-token-hierheen',
        ]);

        $this->expectException(Refusal::class);

        iterator_to_array($this->connector()->pullCustomers());
    }

    public function test_a_next_page_that_was_already_read_stops_the_pull_instead_of_looping(): void
    {
        $this->answers['GET ' . $this->companyPath('/customers')] = fn () => Http::response([
            'value' => [], '@odata.nextLink' => self::ROOT . $this->companyPath('/customers') . '?$skiptoken=1',
        ]);

        $this->expectException(Refusal::class);
        $this->expectExceptionMessage('al gelezen');

        iterator_to_array($this->connector()->pullCustomers());
    }

    public function test_the_quarter_hourly_pull_asks_only_for_what_changed(): void
    {
        $this->answers['GET ' . $this->companyPath('/customers')] = fn () => Http::response(['value' => []]);

        iterator_to_array($this->connector()->pullCustomers(CarbonImmutable::parse('2026-11-19 10:15:00', 'Europe/Amsterdam')));

        $request = $this->sentTo('GET', $this->companyPath('/customers'))[0];
        $this->assertStringContainsString(rawurlencode('lastModifiedDateTime gt 2026-11-19T09:15:00Z'), str_replace('+', '%20', $request->url()));
    }

    public function test_a_customer_is_changed_with_an_etag_and_never_with_null(): void
    {
        $this->connector()->updateCustomer(self::CUSTOMER_ID, CustomerData::from(['name' => 'Garage Smit BV', 'city' => 'Ede']));

        $request = $this->sentTo('PATCH', $this->companyPath('/customers(' . self::CUSTOMER_ID . ')'))[0];
        $this->assertSame('*', $request->header('If-Match')[0]);
        $this->assertSame('Garage Smit BV', $request['displayName']);
        $this->assertSame('', $request['email']);
    }

    public function test_a_customer_made_in_lavoro_goes_to_business_central_and_is_linked(): void
    {
        $this->connection->forceFill([
            'settings' => [...$this->connection->settings, 'customers' => ['read' => true, 'write' => true, 'leading' => 'external']],
        ])->saveQuietly();
        app(Synchronizer::class)->pull($this->connection, Area::Customers);

        $customer = Customer::factory()->create(['name' => 'Nieuw Bedrijf', 'city' => 'Ede', 'iban' => 'NL00BANK0123456789']);

        $created = $this->sentTo('POST', $this->companyPath('/customers'))[0];
        $this->assertSame('Nieuw Bedrijf', $created['displayName']);
        $this->assertArrayNotHasKey('iban', $created, 'Business Central has no IBAN on a customer');
        $this->assertSame('55555555-5555-5555-5555-555555555555', $customer->externalId($this->connection));
    }

    public function test_items_come_in_as_materials_without_the_blocked_ones(): void
    {
        app(Synchronizer::class)->pull($this->connection, Area::Materials);

        $this->assertSame(7.0, (float) Material::where('code', '1000')->value('stock'));
        $this->assertFalse(Material::where('code', '1001')->exists());
    }

    public function test_a_werkbon_becomes_a_sales_order_with_item_lines_only(): void
    {
        $pushed = $this->connector()->pushSalesDocument($this->document([
            new SalesLineData('Filter', 2, 12.5, 21, article_id: self::ITEM_ID),
            new SalesLineData('Los onderdeel', 1, 5, 21),
        ]), DocumentForm::SalesOrder);

        $header = $this->sentTo('POST', $this->companyPath('/salesOrders'))[0];
        $lines = $this->sentTo('POST', $this->companyPath('/salesOrders(' . self::ORDER_ID . ')/salesOrderLines'));
        $this->assertSame(self::CUSTOMER_ID, $header['customerId']);
        $this->assertSame('2026-11-19', $header['orderDate']);
        $this->assertCount(1, $lines);
        $this->assertSame(['Item', self::ITEM_ID, 2.0, 12.5], [$lines[0]['lineType'], $lines[0]['itemId'], $lines[0]['quantity'], $lines[0]['unitPrice']]);
        $this->assertSame([self::ORDER_ID, 'VO1001'], [$pushed->id, $pushed->number]);
        $this->assertSame(['Materiaal zonder Business Central-artikel overgeslagen: Los onderdeel'], $pushed->warnings);
    }

    public function test_a_final_invoice_is_posted_and_comes_back_with_its_posted_number(): void
    {
        $pushed = $this->connector()->pushSalesDocument($this->document([new SalesLineData('Filter', 1, 12.5, 21, article_id: self::ITEM_ID)]), DocumentForm::FinalInvoice);

        $this->assertCount(1, $this->sentTo('POST', $this->companyPath('/salesInvoices(' . self::INVOICE_ID . ')/Microsoft.NAV.post')));
        $this->assertSame('VF2026-001', $pushed->number);
    }

    public function test_a_posted_invoice_whose_number_cannot_be_read_back_still_counts_as_sent(): void
    {
        $this->answers['GET ' . $this->companyPath('/salesInvoices(' . self::INVOICE_ID . ')')] = fn () => Http::response([], 503);

        $pushed = $this->connector()->pushSalesDocument($this->document([new SalesLineData('Filter', 1, 12.5, 21, article_id: self::ITEM_ID)]), DocumentForm::FinalInvoice);

        $this->assertSame(self::INVOICE_ID, $pushed->id);
        $this->assertNull($pushed->number);
        $this->assertStringContainsString('De factuur is geboekt', $pushed->warnings[0]);
        $this->assertSame([], $this->sentTo('DELETE', $this->companyPath('/salesInvoices(' . self::INVOICE_ID . ')')));
    }

    public function test_a_draft_invoice_is_not_posted(): void
    {
        $pushed = $this->connector()->pushSalesDocument($this->document([new SalesLineData('Filter', 1, 12.5, 21, article_id: self::ITEM_ID)]), DocumentForm::DraftInvoice);

        $this->assertSame([], $this->sentTo('POST', $this->companyPath('/salesInvoices(' . self::INVOICE_ID . ')/Microsoft.NAV.post')));
        $this->assertSame('CONCEPT-7', $pushed->number);
    }

    public function test_a_line_that_fails_removes_the_half_made_document(): void
    {
        $this->answers['POST ' . $this->companyPath('/salesOrders(' . self::ORDER_ID . ')/salesOrderLines')] = fn () => Http::response(
            ['error' => ['code' => 'Internal_RecordNotFound', 'message' => 'Het artikel bestaat niet.']], 400,
        );

        try {
            $this->connector()->pushSalesDocument($this->document([new SalesLineData('Filter', 1, 12.5, 21, article_id: self::ITEM_ID)]), DocumentForm::SalesOrder);
            $this->fail('The refused line should have stopped the send.');
        } catch (Refusal $e) {
            $this->assertStringContainsString('Het artikel bestaat niet.', $e->getMessage());
        }

        $this->assertCount(1, $this->sentTo('DELETE', $this->companyPath('/salesOrders(' . self::ORDER_ID . ')')));
    }

    public function test_when_the_half_made_document_cannot_be_removed_either_the_message_says_so(): void
    {
        $this->answers['POST ' . $this->companyPath('/salesOrders(' . self::ORDER_ID . ')/salesOrderLines')] = fn () => Http::response(
            ['error' => ['message' => 'Het artikel bestaat niet.']], 400,
        );
        $this->answers['DELETE ' . $this->companyPath('/salesOrders(' . self::ORDER_ID . ')')] = fn () => Http::response(
            ['error' => ['message' => 'De order is vergrendeld.']], 400,
        );

        $this->expectException(Refusal::class);
        $this->expectExceptionMessage('staat nog in Business Central');

        $this->connector()->pushSalesDocument($this->document([new SalesLineData('Filter', 1, 12.5, 21, article_id: self::ITEM_ID)]), DocumentForm::SalesOrder);
    }

    public function test_an_id_that_is_no_guid_never_reaches_an_address(): void
    {
        $this->assertNull($this->connector()->fetchCustomer('1)/../../companies(' . self::COMPANY));
        $this->assertSame([], array_filter($this->sent, fn (Request $request) => str_contains($request->url(), '..')));
    }

    public function test_a_werkbon_without_any_item_is_refused_before_anything_is_made(): void
    {
        try {
            $this->connector()->pushSalesDocument($this->document([new SalesLineData('Los', 1, 5, 21)]), DocumentForm::SalesOrder);
            $this->fail('A werkbon without items should have been refused.');
        } catch (Refusal) {
        }

        $this->assertSame([], $this->sentTo('POST', $this->companyPath('/salesOrders')));
    }

    public function test_a_busy_business_central_is_worth_another_try_later(): void
    {
        $this->answers['GET ' . $this->companyPath()] = fn () => Http::response([], 429);

        $this->expectException(IntegrationUnavailable::class);

        $this->connector()->test();
    }

    public function test_missing_permissions_are_named(): void
    {
        $this->answers['GET ' . $this->companyPath()] = fn () => Http::response(['error' => ['message' => 'Geen toegang tot tabel Klant.']], 403);

        $this->expectException(Refusal::class);
        $this->expectExceptionMessage('machtigingensets');

        $this->connector()->test();
    }

    public function test_a_webhook_is_subscribed_for_the_company_list_and_renewed_with_its_etag(): void
    {
        $webhook = new IntegrationWebhook(['area' => 'customers', 'token' => 'webhook-token']);

        $subscription = $this->connector()->subscribe(Area::Customers, 'https://lavoro.test/integrations/webhook/webhook-token', $webhook);

        $created = $this->sentTo('POST', '/subscriptions')[0];
        $this->assertSame('/api/v2.0' . $this->companyPath('/customers'), $created['resource']);
        $this->assertSame('webhook-token', $created['clientState']);
        $this->assertSame('sub-1', $subscription->id);

        $webhook->external_id = 'sub-1';
        $renewed = $this->connector()->subscribe(Area::Customers, 'https://lavoro.test/integrations/webhook/webhook-token', $webhook);

        $this->assertSame('W/"etag-1"', $this->sentTo('PATCH', "/subscriptions('sub-1')")[0]->header('If-Match')[0]);
        $this->assertSame('2026-11-25', $renewed->expires_at->toDateString());
    }

    public function test_notices_are_read_only_from_its_own_subscription(): void
    {
        $webhook = new IntegrationWebhook(['area' => 'customers', 'token' => 'webhook-token']);
        $request = \Illuminate\Http\Request::create('/', 'POST', ['value' => [
            ['clientState' => 'webhook-token', 'changeType' => 'updated', 'resource' => 'api/v2.0' . $this->companyPath('/customers(' . self::CUSTOMER_ID . ')')],
            ['clientState' => 'iemand-anders', 'changeType' => 'updated', 'resource' => 'api/v2.0' . $this->companyPath('/customers(' . self::CUSTOMER_ID . ')')],
            ['clientState' => 'webhook-token', 'changeType' => 'collection', 'resource' => 'api/v2.0' . $this->companyPath('/items')],
        ]]);

        $notices = $this->connector()->notices($request, $webhook);

        $this->assertCount(2, $notices);
        $this->assertSame([Area::Customers, self::CUSTOMER_ID], [$notices[0]->area, $notices[0]->external_id]);
        $this->assertSame([Area::Materials, null], [$notices[1]->area, $notices[1]->external_id]);
    }

    public function test_the_validation_call_gets_its_token_back(): void
    {
        $webhook = new IntegrationWebhook(['area' => 'customers', 'token' => 'webhook-token']);

        $response = $this->connector()->handshake(\Illuminate\Http\Request::create('/?validationToken=abc-123', 'POST'), $webhook);

        $this->assertSame('abc-123', $response->getContent());
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
    }

    public function test_a_notice_through_the_webhook_address_brings_the_customer_in(): void
    {
        $webhook = $this->connection->webhooks()->create(['area' => 'customers', 'token' => Str::random(48)]);
        IntegrationWebhookLookup::create([
            'token_hash' => hash('sha256', $webhook->token),
            'tenant_id' => tenancy()->tenant->getTenantKey(),
            'integration_webhook_id' => $webhook->id,
        ]);

        $this->postJson('/integrations/webhook/' . $webhook->token, ['value' => [[
            'clientState' => $webhook->token,
            'changeType' => 'created',
            'resource' => 'api/v2.0' . $this->companyPath('/customers(' . self::CUSTOMER_ID . ')'),
        ]]])->assertStatus(202);

        $this->assertSame(self::CUSTOMER_ID, Customer::where('name', 'Garage Smit')->firstOrFail()->externalId($this->connection));
    }
}

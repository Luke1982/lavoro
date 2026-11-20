<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Connectors\Moneybird\MoneybirdConnector;
use App\Domain\Integrations\Data\CustomerData;
use App\Domain\Integrations\Data\SalesDocumentData;
use App\Domain\Integrations\Data\SalesLineData;
use App\Domain\Integrations\DocumentForm;
use App\Domain\Integrations\IntegrationUnavailable;
use App\Domain\Integrations\Sync\Synchronizer;
use App\Exceptions\Refusal;
use App\Models\Customer;
use App\Models\IntegrationConnection;
use App\Models\IntegrationWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as IncomingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Moneybird behind the standard shapes, against a fake of its API that keeps
 * what was sent.
 */
class MoneybirdConnectorTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private const API = 'https://moneybird.com/api/v2';

    private const ADMINISTRATION = '1001';

    private IntegrationConnection $connection;

    /** @var array<int, Request> */
    private array $sent = [];

    /** @var array<string, \Closure(Request): mixed> */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.moneybird.client_id' => 'lavoro-app', 'services.moneybird.client_secret' => 'app-geheim']);

        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->answer($request));

        $this->connection = IntegrationConnection::open('moneybird');
        $this->connection->settings = [...$this->connection->settings, 'customers' => ['read' => true, 'write' => false, 'leading' => 'external'],
            'options' => ['administration_id' => self::ADMINISTRATION]];
        $this->connection->storeCredentials(['access_token' => 'token-0']);
        $this->connection->saveQuietly();
    }

    private function answer(Request $request)
    {
        $this->sent[] = $request;

        if (str_starts_with($request->url(), 'https://moneybird.com/oauth/token')) {
            return Http::response(['access_token' => 'token-1', 'token_type' => 'bearer', 'refresh_token' => 'refresh-1']);
        }

        $path = Str::before(Str::after($request->url(), self::API), '?');
        $key = $request->method() . ' ' . Str::after($path, '/' . self::ADMINISTRATION);

        if (isset($this->answers[$key])) {
            return ($this->answers[$key])($request);
        }

        return match (true) {
            $path === '/administrations.json' => Http::response([['id' => 1001, 'name' => 'Garage Smit'], ['id' => 1002, 'name' => 'Smit Holding']]),
            $key === 'GET /contacts.json' => Http::response($request['page'] == 1 ? [$this->contact('1', 'Garage Smit')] : []),
            $key === 'GET /contacts/filter.json' => Http::response([]),
            $key === 'GET /contacts/1.json' => Http::response($this->contact('1', 'Garage Smit')),
            $key === 'PATCH /contacts/1.json' => Http::response([...$this->contact('1', ''), ...$request['contact']]),
            $key === 'POST /contacts.json' => Http::response([...$request['contact'], 'id' => '2'], 201),
            $key === 'GET /products.json' => Http::response($request['page'] == 1 ? [['id' => '501', 'title' => 'Filter', 'identifier' => 'FLT', 'price' => '12.50']] : []),
            $key === 'GET /tax_rates.json' => Http::response([['id' => 'btw21', 'percentage' => '21.0'], ['id' => 'btw9', 'percentage' => '9.0']]),
            $key === 'POST /sales_invoices.json' => Http::response(['id' => '7001', 'invoice_id' => null, 'state' => 'draft'], 201),
            $key === 'PATCH /sales_invoices/7001/send_invoice.json' => Http::response(['id' => '7001', 'invoice_id' => '2026-0042', 'state' => 'open']),
            $key === 'DELETE /sales_invoices/7001.json' => Http::response(null, 204),
            $key === 'POST /webhooks.json' => Http::response(['id' => '9001', 'token' => 'hun-token', 'secret' => 'hun-geheim'], 201),
        };
    }

    private function contact(string $id, string $company): array
    {
        return ['id' => $id, 'company_name' => $company, 'firstname' => 'Jan', 'lastname' => 'Smit', 'attention' => 'Jan Smit',
            'address1' => 'Kerkweg 3', 'zipcode' => '1234 AB', 'city' => 'Ede', 'country' => 'NL', 'email' => 'info@smit.nl',
            'send_invoices_to_email' => 'factuur@smit.nl', 'tax_number' => 'NL001', 'chamber_of_commerce' => '12345678', 'bank_account' => 'NL00BANK0123456789'];
    }

    private function connector(): MoneybirdConnector
    {
        return $this->connection->fresh()->connector();
    }

    /** @return array<int, Request> */
    private function sentTo(string $method, string $path): array
    {
        return array_values(array_filter($this->sent, fn (Request $request) => $request->method() === $method
            && Str::before(Str::after($request->url(), self::API . '/' . self::ADMINISTRATION), '?') === $path));
    }

    private function document(array $lines): SalesDocumentData
    {
        return new SalesDocumentData('Werkbon 12', 'Werkbon 12', CarbonImmutable::parse('2026-11-19'), '1',
            CustomerData::from(['name' => 'Garage Smit']), $lines, memo: 'PO-77');
    }

    private function notice(array $payload, string $secret = 'hun-geheim', ?int $time = null): IncomingRequest
    {
        $body = json_encode($payload);
        $time ??= now()->getTimestamp();
        $request = IncomingRequest::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        $request->headers->set('Moneybird-Signature', 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, $secret));

        return $request;
    }

    public function test_connecting_logs_in_and_names_every_administration_when_there_are_more(): void
    {
        $this->connection->delete();
        $user = $this->userWith('integration.manage');

        $start = $this->actingAs($user)->get('/integrations/moneybird/oauth');
        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('sales_invoices settings', $query['scope']);

        $this->actingAs($user)->get('/integrations/oauth/callback?code=de-code&state=' . $query['state'])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Smit Holding (1002)'));

        $connection = IntegrationConnection::where('connector', 'moneybird')->firstOrFail();
        $this->assertSame('token-1', $connection->credentials()['access_token']);
        $this->assertSame(self::ADMINISTRATION, $connection->option('administration_id'));
    }

    public function test_contacts_come_in_with_their_company_name_and_the_rest(): void
    {
        app(Synchronizer::class)->pull($this->connection->fresh(), Area::Customers);

        $customer = Customer::where('name', 'Garage Smit')->firstOrFail();
        $this->assertSame(['factuur@smit.nl', 'NL00BANK0123456789', 'Jan Smit'], [$customer->invoice_email, $customer->iban, $customer->contactname]);
        $this->assertEquals(100, $this->sentTo('GET', '/contacts.json')[0]['per_page']);
    }

    public function test_a_person_without_a_company_name_comes_in_by_first_and_last_name(): void
    {
        $this->answers['GET /contacts/3.json'] = fn () => Http::response([...$this->contact('3', ''), 'company_name' => null]);

        $this->assertSame('Jan Smit', $this->connector()->fetchCustomer('3')->data->name);
    }

    public function test_the_quarter_hourly_pull_asks_for_contacts_changed_since(): void
    {
        iterator_to_array($this->connector()->pullCustomers(CarbonImmutable::parse('2026-11-19 10:15:00', 'UTC')));

        $this->assertSame('updated_after:2026-11-19T10:15:00Z', $this->sentTo('GET', '/contacts/filter.json')[0]['filter']);
    }

    public function test_a_customer_goes_back_with_the_name_as_company_name(): void
    {
        $this->connector()->updateCustomer('1', CustomerData::from(['name' => 'Garage Smit BV', 'invoice_email' => 'nieuw@smit.nl']));

        $sent = $this->sentTo('PATCH', '/contacts/1.json')[0]['contact'];
        $this->assertSame(['Garage Smit BV', 'nieuw@smit.nl'], [$sent['company_name'], $sent['send_invoices_to_email']]);
    }

    public function test_a_final_invoice_is_made_excluding_vat_with_moneybirds_own_rate_and_sent_by_hand(): void
    {
        $pushed = $this->connector()->pushSalesDocument($this->document([
            new SalesLineData('Filter', 2, 12.5, 21, article_id: '501'),
            new SalesLineData('Voorrijkosten', 1, 35, 21),
        ]), DocumentForm::FinalInvoice);

        $invoice = $this->sentTo('POST', '/sales_invoices.json')[0]['sales_invoice'];
        $this->assertFalse($invoice['prices_are_incl_tax']);
        $this->assertSame('Werkbon 12 (PO-77)', $invoice['reference']);
        $this->assertSame(['description' => 'Filter', 'price' => '12.50', 'amount' => '2', 'tax_rate_id' => 'btw21', 'product_id' => '501'], $invoice['details_attributes'][0]);
        $this->assertArrayNotHasKey('product_id', $invoice['details_attributes'][1]);
        $this->assertSame('Manual', $this->sentTo('PATCH', '/sales_invoices/7001/send_invoice.json')[0]['sales_invoice_sending']['delivery_method']);
        $this->assertSame('2026-0042', $pushed->number);
    }

    public function test_a_draft_invoice_is_not_sent(): void
    {
        $this->connector()->pushSalesDocument($this->document([new SalesLineData('Filter', 1, 12.5, 21)]), DocumentForm::DraftInvoice);

        $this->assertSame([], $this->sentTo('PATCH', '/sales_invoices/7001/send_invoice.json'));
    }

    public function test_a_vat_rate_moneybird_does_not_have_is_refused_before_anything_is_made(): void
    {
        try {
            $this->connector()->pushSalesDocument($this->document([new SalesLineData('Filter', 1, 12.5, 6)]), DocumentForm::DraftInvoice);
            $this->fail('A missing VAT rate should have been refused.');
        } catch (Refusal $e) {
            $this->assertStringContainsString('6%', $e->getMessage());
        }

        $this->assertSame([], $this->sentTo('POST', '/sales_invoices.json'));
    }

    public function test_an_invoice_that_cannot_be_sent_is_removed_again(): void
    {
        $this->answers['PATCH /sales_invoices/7001/send_invoice.json'] = fn () => Http::response(['error' => 'Contact heeft geen adres', 'details' => ['contact' => ['address1' => ['is leeg']]]], 422);

        try {
            $this->connector()->pushSalesDocument($this->document([new SalesLineData('Filter', 1, 12.5, 21)]), DocumentForm::FinalInvoice);
            $this->fail('The refused sending should have stopped the send.');
        } catch (Refusal $e) {
            $this->assertStringContainsString('Contact heeft geen adres', $e->getMessage());
            $this->assertStringContainsString('contact.address1: is leeg', $e->getMessage());
        }

        $this->assertCount(1, $this->sentTo('DELETE', '/sales_invoices/7001.json'));
    }

    public function test_moneybirds_limit_is_worth_another_try_later(): void
    {
        $this->answers['GET /contacts.json'] = fn () => Http::response(['error' => 'Too many requests'], 429);

        $this->expectException(IntegrationUnavailable::class);

        $this->connector()->test();
    }

    public function test_a_webhook_keeps_moneybirds_secret_and_only_signed_fresh_notices_count(): void
    {
        $webhook = new IntegrationWebhook(['area' => Area::Customers, 'token' => 'url-token']);
        $subscription = $this->connector()->subscribe(Area::Customers, 'https://lavoro.test/integrations/webhook/url-token', $webhook);

        $this->assertSame(['9001', 'hun-geheim'], [$subscription->id, $subscription->signing_secret]);
        $this->assertContains('contact_changed', $this->sentTo('POST', '/webhooks.json')[0]['enabled_events']);

        $webhook->signing_secret = 'hun-geheim';
        $payload = ['administration_id' => 1001, 'entity_type' => 'Contact', 'entity_id' => '1', 'action' => 'contact_changed'];

        $this->assertSame('1', $this->connector()->notices($this->notice($payload), $webhook)[0]->external_id);
        $this->assertSame([], $this->connector()->notices($this->notice($payload, 'verkeerd'), $webhook));
        $this->assertSame([], $this->connector()->notices($this->notice($payload, time: now()->subMinutes(10)->getTimestamp()), $webhook));
        $this->assertSame([], $this->connector()->notices($this->notice([...$payload, 'administration_id' => 666]), $webhook));
    }

    public function test_reconnecting_with_a_login_that_cannot_see_the_chosen_administration_is_refused(): void
    {
        $this->connection->setOption('administration_id', '4040');
        $this->connection->saveQuietly();
        $user = $this->userWith('integration.manage');
        $start = $this->actingAs($user)->get('/integrations/moneybird/oauth');
        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->actingAs($user)->get('/integrations/oauth/callback?code=de-code&state=' . $query['state'])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'kan administratie 4040 niet zien'));

        $connection = $this->connection->fresh();
        $this->assertSame('4040', $connection->option('administration_id'));
        $this->assertSame('token-0', $connection->credentials()['access_token'], 'nothing of the refused login was kept');
    }

    public function test_a_notice_is_read_from_the_signed_body_and_not_from_the_address(): void
    {
        $webhook = new IntegrationWebhook(['area' => Area::Customers, 'token' => 'url-token', 'signing_secret' => 'hun-geheim']);
        $request = $this->notice(['administration_id' => 1001, 'entity_type' => 'Contact', 'entity_id' => '1']);
        $request->query->set('entity_id', '666');

        $this->assertSame('1', $this->connector()->notices($request, $webhook)[0]->external_id);
    }
}

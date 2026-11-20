<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Connectors\Exact\ExactConnector;
use App\Domain\Integrations\Data\CustomerData;
use App\Domain\Integrations\Data\LedgerAccountData;
use App\Domain\Integrations\Data\SalesDocumentData;
use App\Domain\Integrations\Data\SalesLineData;
use App\Domain\Integrations\DocumentForm;
use App\Domain\Integrations\IntegrationUnavailable;
use App\Domain\Integrations\Sync\Synchronizer;
use App\Exceptions\Refusal;
use App\Models\Customer;
use App\Models\IntegrationConnection;
use App\Models\IntegrationWebhook;
use App\Models\LedgerAccount;
use App\Models\Material;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as IncomingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Exact Online behind the standard shapes, against a fake of its API that
 * keeps what was sent: the login through MajorLabel's app, renewing tokens,
 * the bulk and changes-only pulls, documents and webhooks.
 */
class ExactConnectorTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private const BASE = 'https://start.exactonline.nl';

    private const DIVISION = '123';

    private const ACCOUNT_ID = '33333333-3333-3333-3333-333333333333';

    private const ITEM_ID = '44444444-4444-4444-4444-444444444444';

    private const INVOICE_ID = '99999999-9999-9999-9999-999999999999';

    private const GL_ACCOUNT_ID = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

    private IntegrationConnection $connection;

    /** @var array<int, Request> */
    private array $sent = [];

    /** @var array<string, \Closure(Request): mixed> */
    private array $answers = [];

    private int $tokens_handed_out = 0;

    /** @var array{0: int, 1: array}|null What the token address answers instead of tokens. */
    private ?array $token_failure = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.exact.client_id' => 'lavoro-app',
            'services.exact.client_secret' => 'app-geheim',
            'services.exact.webhook_secret' => 'webhook-geheim',
        ]);

        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->answer($request));

        $this->connection = IntegrationConnection::open('exactonline');
        $this->connection->settings = [...$this->connection->settings, 'customers' => ['read' => true, 'write' => false, 'leading' => 'external'],
            'options' => ['division' => self::DIVISION, 'sales_journal' => '70']];
        $this->connection->storeCredentials(['access_token' => 'token-0', 'refresh_token' => 'refresh-0', 'expires_at' => (string) now()->addMinutes(9)->getTimestamp()]);
        $this->connection->saveQuietly();
    }

    private function answer(Request $request)
    {
        $this->sent[] = $request;
        $path = Str::before(Str::after($request->url(), self::BASE), '?');

        if ($path === '/api/oauth2/token' && $this->token_failure) {
            return Http::response($this->token_failure[1], $this->token_failure[0]);
        }

        if ($path === '/api/oauth2/token') {
            $this->tokens_handed_out++;

            return Http::response(['access_token' => 'token-' . $this->tokens_handed_out, 'refresh_token' => 'refresh-' . $this->tokens_handed_out, 'expires_in' => 600]);
        }

        $key = $request->method() . ' ' . Str::after($path, '/api/v1');

        if (isset($this->answers[$key])) {
            return ($this->answers[$key])($request);
        }

        $division = '/' . self::DIVISION;

        return match ($key) {
            'GET /current/Me' => Http::response(['d' => ['results' => [['CurrentDivision' => 123]]]]),
            'GET ' . $division . '/bulk/CRM/Accounts' => Http::response(['d' => ['results' => [$this->account(self::ACCOUNT_ID, 'Garage Smit')]]]),
            'GET ' . $division . '/crm/Accounts' => Http::response(['d' => ['results' => []]]),
            'GET ' . $division . "/crm/Accounts(guid'" . self::ACCOUNT_ID . "')" => Http::response(['d' => $this->account(self::ACCOUNT_ID, 'Garage Smit BV')]),
            'PUT ' . $division . "/crm/Accounts(guid'" . self::ACCOUNT_ID . "')" => Http::response(null, 204),
            'POST ' . $division . '/crm/Accounts' => Http::response(['d' => [...$request->data(), 'ID' => '55555555-5555-5555-5555-555555555555']], 201),
            'GET ' . $division . '/bulk/Logistics/Items' => Http::response(['d' => ['results' => [
                ['ID' => self::ITEM_ID, 'Code' => '       FLT', 'Description' => 'Filter', 'StandardSalesPrice' => 12.5, 'CostPriceStandard' => 4.1, 'Stock' => 7, 'IsSalesItem' => true, 'EndDate' => null],
                ['ID' => '66666666-6666-6666-6666-666666666666', 'Code' => 'OUD', 'Description' => 'Oud', 'IsSalesItem' => true, 'EndDate' => '/Date(946684800000)/'],
                ['ID' => '77777777-7777-7777-7777-777777777777', 'Code' => 'INKOOP', 'Description' => 'Inkoop', 'IsSalesItem' => false, 'EndDate' => null],
            ]]]),
            'POST ' . $division . '/salesorder/SalesOrders' => Http::response(['d' => ['OrderID' => '88888888-8888-8888-8888-888888888888', 'OrderNumber' => 501]], 201),
            'POST ' . $division . '/salesinvoice/SalesInvoices' => Http::response(['d' => ['InvoiceID' => self::INVOICE_ID, 'InvoiceNumber' => null, 'Status' => 20]], 201),
            'POST ' . $division . '/salesinvoice/PrintedSalesInvoices' => Http::response(['d' => ['InvoiceID' => self::INVOICE_ID]], 201),
            'GET ' . $division . "/salesinvoice/SalesInvoices(guid'" . self::INVOICE_ID . "')" => Http::response(['d' => ['InvoiceNumber' => 26000042]]),
            'DELETE ' . $division . "/salesinvoice/SalesInvoices(guid'" . self::INVOICE_ID . "')" => Http::response(null, 204),
            'POST ' . $division . '/webhooks/WebhookSubscriptions' => Http::response(['d' => ['ID' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa']], 201),
            'GET ' . $division . '/bulk/Financial/GLAccounts' => Http::response(['d' => ['results' => [
                ['ID' => self::GL_ACCOUNT_ID, 'Code' => '8000  ', 'Description' => 'Omzet hoog', 'Type' => 110, 'IsBlocked' => false],
                ['ID' => 'cccccccc-cccc-cccc-cccc-cccccccccccc', 'Code' => '8999', 'Description' => 'Geblokkeerd', 'Type' => 110, 'IsBlocked' => true],
            ]]]),
            'POST ' . $division . '/financial/GLAccounts' => Http::response(['d' => [...$request->data(), 'ID' => self::GL_ACCOUNT_ID]], 201),
        };
    }

    private function account(string $id, string $name): array
    {
        return ['ID' => $id, 'Name' => $name, 'Email' => 'info@smit.nl', 'AddressLine1' => 'Kerkweg 3', 'Postcode' => '1234 AB',
            'City' => 'Ede', 'Country' => 'NL', 'VATNumber' => 'NL001', 'ChamberOfCommerce' => '12345678', 'Website' => null, 'Phone' => null];
    }

    private function connector(): ExactConnector
    {
        return $this->connection->fresh()->connector();
    }

    /** @return array<int, Request> */
    private function sentTo(string $method, string $path): array
    {
        return array_values(array_filter($this->sent, fn (Request $request) => $request->method() === $method
            && Str::before(Str::after($request->url(), self::BASE . '/api/v1'), '?') === $path));
    }

    private function document(array $lines): SalesDocumentData
    {
        return new SalesDocumentData('Werkbon 12', 'Werkbon 12', CarbonImmutable::parse('2026-11-19'), self::ACCOUNT_ID,
            CustomerData::from(['name' => 'Garage Smit']), $lines, memo: 'PO-77');
    }

    private function notice(array $content, ?string $secret = 'webhook-geheim'): IncomingRequest
    {
        $json = json_encode($content);

        return IncomingRequest::create('/', 'POST', [], [], [], [], '{"Content":' . $json . ',"HashCode":"' . strtoupper(hash_hmac('sha256', $json, (string) $secret)) . '"}');
    }

    public function test_connecting_logs_in_at_exact_and_takes_the_administration_you_were_in(): void
    {
        $this->connection->delete();
        $user = $this->userWith('integration.manage');

        $start = $this->actingAs($user)->get('/integrations/exactonline/oauth');
        $start->assertRedirect();
        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertStringStartsWith(self::BASE . '/api/oauth2/auth', $start->headers->get('Location'));
        $this->assertSame('lavoro-app', $query['client_id']);

        $this->actingAs($user)->get('/integrations/oauth/callback?code=de-code&state=' . $query['state'])
            ->assertRedirect('/integrations')
            ->assertSessionHas('success', 'Exact Online is gekoppeld. Administratie 123.');

        $connection = IntegrationConnection::where('connector', 'exactonline')->firstOrFail();
        $this->assertSame('token-1', $connection->credentials()['access_token']);
        $this->assertSame(self::DIVISION, $connection->option('division'));
        $exchange = collect($this->sent)->first(fn (Request $request) => str_ends_with($request->url(), '/api/oauth2/token'));
        $this->assertSame(['authorization_code', 'de-code'], [$exchange['grant_type'], $exchange['code']]);
    }

    public function test_a_refused_login_is_shown_on_the_koppelingen_page_and_not_back_at_exact(): void
    {
        $user = $this->userWith('integration.manage');
        $start = $this->actingAs($user)->get('/integrations/exactonline/oauth');
        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->token_failure = [400, ['error' => 'invalid_grant', 'error_description' => 'Code verlopen']];

        $this->actingAs($user)->withHeader('Referer', self::BASE . '/api/oauth2/auth')
            ->get('/integrations/oauth/callback?code=oud&state=' . $query['state'])
            ->assertRedirect('/integrations')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Code verlopen'));
    }

    public function test_an_answer_that_does_not_belong_to_a_start_here_is_refused(): void
    {
        $user = $this->userWith('integration.manage');
        $this->actingAs($user)->get('/integrations/exactonline/oauth');

        $this->actingAs($user)->get('/integrations/oauth/callback?code=de-code&state=iets-anders')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'niet afgemaakt'));
        $this->assertSame(0, $this->tokens_handed_out);
    }

    public function test_without_the_app_registered_connecting_says_who_has_to_do_what(): void
    {
        config(['services.exact.client_id' => null]);

        $this->actingAs($this->userWith('integration.manage'))->get('/integrations/exactonline/oauth')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'nog niet geregistreerd'));
    }

    public function test_an_expired_token_is_renewed_once_and_the_new_refresh_token_is_kept(): void
    {
        $this->connection->storeCredentials(['access_token' => 'token-0', 'refresh_token' => 'refresh-0', 'expires_at' => (string) now()->subMinute()->getTimestamp()]);
        $this->connection->saveQuietly();

        $this->connector()->test();
        $this->connector()->test();

        $this->assertSame(1, $this->tokens_handed_out);
        $refresh = collect($this->sent)->first(fn (Request $request) => str_ends_with($request->url(), '/api/oauth2/token'));
        $this->assertSame(['refresh_token', 'refresh-0'], [$refresh['grant_type'], $refresh['refresh_token']]);
        $this->assertSame('refresh-1', $this->connection->fresh()->credentials()['refresh_token']);
        $this->assertSame('Bearer token-1', last($this->sent)->header('Authorization')[0]);
    }

    public function test_a_full_pull_goes_through_the_bulk_endpoint_for_customers_only_page_by_page(): void
    {
        $this->answers['GET /' . self::DIVISION . '/bulk/CRM/Accounts'] = fn (Request $request) => str_contains($request->url(), 'skiptoken')
            ? Http::response(['d' => ['results' => [$this->account('77777777-7777-7777-7777-777777777777', 'Bakkerij Jansen')]]])
            : Http::response(['d' => ['results' => [$this->account(self::ACCOUNT_ID, 'Garage Smit')],
                '__next' => self::BASE . '/api/v1/' . self::DIVISION . '/bulk/CRM/Accounts?$skiptoken=guid\'1\'']]);

        app(Synchronizer::class)->pull($this->connection->fresh(), Area::Customers);

        $this->assertSame(['Garage Smit', 'Bakkerij Jansen'], Customer::whereIn('name', ['Garage Smit', 'Bakkerij Jansen'])->orderBy('id')->pluck('name')->all());
        $this->assertSame('12345678', Customer::where('name', 'Garage Smit')->value('chamber_of_commerce_number'));
        $first = $this->sentTo('GET', '/' . self::DIVISION . '/bulk/CRM/Accounts')[0];
        $this->assertStringContainsString(rawurlencode("Status eq 'C'"), str_replace('+', '%20', $first->url()));
    }

    public function test_the_quarter_hourly_pull_asks_for_what_changed_since(): void
    {
        iterator_to_array($this->connector()->pullCustomers(CarbonImmutable::parse('2026-11-19 10:15:00', 'UTC')));

        $request = $this->sentTo('GET', '/' . self::DIVISION . '/crm/Accounts')[0];
        $this->assertStringContainsString(rawurlencode("Modified gt datetime'2026-11-19T09:15:00'"), str_replace('+', '%20', $request->url()));
    }

    public function test_a_customer_is_updated_and_read_back(): void
    {
        $record = $this->connector()->updateCustomer(self::ACCOUNT_ID, CustomerData::from(['name' => 'Garage Smit BV', 'city' => 'Ede']));

        $put = $this->sentTo('PUT', '/' . self::DIVISION . "/crm/Accounts(guid'" . self::ACCOUNT_ID . "')")[0];
        $this->assertSame('Garage Smit BV', $put['Name']);
        $this->assertSame('', $put['Email']);
        $this->assertSame('Garage Smit BV', $record->data->name);
    }

    public function test_only_sellable_articles_that_have_not_ended_come_in(): void
    {
        $this->connection->settings = [...$this->connection->settings, 'materials' => ['read' => true]];
        $this->connection->saveQuietly();

        app(Synchronizer::class)->pull($this->connection->fresh(), Area::Materials);

        $this->assertSame(12.5, (float) Material::where('code', 'FLT')->value('price'));
        $this->assertFalse(Material::whereIn('code', ['OUD', 'INKOOP'])->exists());
    }

    public function test_a_werkbon_becomes_a_sales_order_with_its_lines_in_one_call(): void
    {
        $pushed = $this->connector()->pushSalesDocument($this->document([
            new SalesLineData('Filter', 2, 12.5, 21, article_id: self::ITEM_ID),
            new SalesLineData('Los onderdeel', 1, 5, 21),
        ]), DocumentForm::SalesOrder);

        $order = $this->sentTo('POST', '/' . self::DIVISION . '/salesorder/SalesOrders')[0];
        $this->assertSame(self::ACCOUNT_ID, $order['OrderedBy']);
        $this->assertSame('PO-77', $order['YourRef']);
        $this->assertSame([['Item' => self::ITEM_ID, 'Description' => 'Filter', 'Quantity' => 2.0, 'UnitPrice' => 12.5]], $order['SalesOrderLines']);
        $this->assertSame('501', $pushed->number);
        $this->assertSame(['Materiaal zonder artikel in Exact Online overgeslagen: Los onderdeel'], $pushed->warnings);
    }

    public function test_a_final_invoice_is_processed_without_mailing_and_comes_back_with_its_number(): void
    {
        $pushed = $this->connector()->pushSalesDocument($this->document([new SalesLineData('Filter', 1, 12.5, 21, article_id: self::ITEM_ID)]), DocumentForm::FinalInvoice);

        $invoice = $this->sentTo('POST', '/' . self::DIVISION . '/salesinvoice/SalesInvoices')[0];
        $printed = $this->sentTo('POST', '/' . self::DIVISION . '/salesinvoice/PrintedSalesInvoices')[0];
        $this->assertSame('70', $invoice['Journal']);
        $this->assertSame([self::INVOICE_ID, false], [$printed['InvoiceID'], $printed['SendEmailToCustomer']]);
        $this->assertSame('26000042', $pushed->number);
    }

    public function test_a_draft_invoice_is_not_processed(): void
    {
        $this->connector()->pushSalesDocument($this->document([new SalesLineData('Filter', 1, 12.5, 21, article_id: self::ITEM_ID)]), DocumentForm::DraftInvoice);

        $this->assertSame([], $this->sentTo('POST', '/' . self::DIVISION . '/salesinvoice/PrintedSalesInvoices'));
    }

    public function test_an_invoice_that_cannot_be_processed_is_removed_again(): void
    {
        $this->answers['POST /' . self::DIVISION . '/salesinvoice/PrintedSalesInvoices'] = fn () => Http::response(
            ['error' => ['code' => '', 'message' => ['lang' => 'nl', 'value' => 'Geen standaard lay-out ingesteld.']]], 400,
        );

        try {
            $this->connector()->pushSalesDocument($this->document([new SalesLineData('Filter', 1, 12.5, 21, article_id: self::ITEM_ID)]), DocumentForm::FinalInvoice);
            $this->fail('The refused processing should have stopped the send.');
        } catch (Refusal $e) {
            $this->assertStringContainsString('Geen standaard lay-out ingesteld.', $e->getMessage());
        }

        $this->assertCount(1, $this->sentTo('DELETE', '/' . self::DIVISION . "/salesinvoice/SalesInvoices(guid'" . self::INVOICE_ID . "')"));
    }

    public function test_a_busy_exact_is_worth_another_try_later(): void
    {
        $this->answers['GET /' . self::DIVISION . '/crm/Accounts'] = fn () => Http::response([], 429);

        $this->expectException(IntegrationUnavailable::class);

        $this->connector()->test();
    }

    public function test_a_webhook_is_subscribed_per_topic_and_only_signed_notices_about_this_administration_count(): void
    {
        $webhook = new IntegrationWebhook(['area' => Area::Customers, 'token' => 'url-token']);
        $subscription = $this->connector()->subscribe(Area::Customers, 'https://lavoro.test/integrations/webhook/url-token', $webhook);

        $sent = $this->sentTo('POST', '/' . self::DIVISION . '/webhooks/WebhookSubscriptions')[0];
        $this->assertSame(['https://lavoro.test/integrations/webhook/url-token', 'Accounts'], [$sent['CallbackURL'], $sent['Topic']]);
        $this->assertNull($subscription->expires_at);

        $content = ['Topic' => 'Accounts', 'Division' => 123, 'Action' => 'Update', 'Key' => self::ACCOUNT_ID];
        $this->assertSame(self::ACCOUNT_ID, $this->connector()->notices($this->notice($content), $webhook)[0]->external_id);
        $this->assertSame([], $this->connector()->notices($this->notice($content, 'verkeerd-geheim'), $webhook));
        $this->assertSame([], $this->connector()->notices($this->notice([...$content, 'Division' => 999]), $webhook));
    }

    public function test_exacts_check_of_a_new_address_gets_a_plain_yes(): void
    {
        $webhook = new IntegrationWebhook(['area' => Area::Customers, 'token' => 'url-token']);

        $this->assertSame(200, $this->connector()->handshake(IncomingRequest::create('/', 'POST'), $webhook)->getStatusCode());
    }

    public function test_a_renewed_token_survives_a_pull_that_fails_afterwards(): void
    {
        $this->connection->storeCredentials(['access_token' => 'token-0', 'refresh_token' => 'refresh-0', 'expires_at' => (string) now()->subMinute()->getTimestamp()]);
        $this->connection->saveQuietly();
        $this->answers['GET /' . self::DIVISION . '/bulk/CRM/Accounts'] = fn () => Http::response(['error' => ['message' => ['value' => 'Kapot']]], 400);

        try {
            app(Synchronizer::class)->pull($this->connection->fresh(), Area::Customers);
            $this->fail('The refused page should have stopped the pull.');
        } catch (Refusal) {
        }

        $this->assertSame('refresh-1', $this->connection->fresh()->credentials()['refresh_token'], 'the only refresh token that still works was kept');
    }

    public function test_only_revenue_accounts_that_are_not_blocked_come_in(): void
    {
        $this->connection->settings = [...$this->connection->settings, 'ledger_accounts' => ['read' => true, 'write' => false, 'leading' => 'external']];
        $this->connection->saveQuietly();

        app(Synchronizer::class)->pull($this->connection->fresh(), Area::LedgerAccounts);

        $this->assertStringContainsString('Type eq 110', urldecode($this->sentTo('GET', '/' . self::DIVISION . '/bulk/Financial/GLAccounts')[0]->url()));
        $this->assertSame(['8000'], LedgerAccount::pluck('code')->all());
        $this->assertSame(self::GL_ACCOUNT_ID, LedgerAccount::firstOrFail()->externalId($this->connection));
    }

    public function test_an_account_made_in_lavoro_becomes_a_revenue_account_there(): void
    {
        $record = $this->connector()->createLedgerAccount(LedgerAccountData::from(['code' => '8200', 'name' => 'Omzet service']));

        $this->assertSame(self::GL_ACCOUNT_ID, $record->id);
        $this->assertSame(
            ['Code' => '8200', 'Description' => 'Omzet service', 'Type' => 110, 'BalanceType' => 'W', 'BalanceSide' => 'C'],
            $this->sentTo('POST', '/' . self::DIVISION . '/financial/GLAccounts')[0]->data(),
        );
    }

    public function test_an_account_without_a_number_is_refused_before_anything_is_sent(): void
    {
        try {
            $this->connector()->createLedgerAccount(LedgerAccountData::from(['name' => 'Zonder nummer']));
            $this->fail('An account without a number should have been refused.');
        } catch (Refusal $e) {
            $this->assertStringContainsString('geen nummer', $e->getMessage());
        }

        $this->assertSame([], $this->sentTo('POST', '/' . self::DIVISION . '/financial/GLAccounts'));
    }

    public function test_an_invoice_line_goes_onto_the_materials_account_and_a_sales_order_line_has_none(): void
    {
        $line = new SalesLineData('Filter', 1, 12.5, 21, article_id: self::ITEM_ID, ledger_account_id: self::GL_ACCOUNT_ID);

        $this->connector()->pushSalesDocument($this->document([$line]), DocumentForm::DraftInvoice);
        $this->connector()->pushSalesDocument($this->document([$line]), DocumentForm::SalesOrder);

        $this->assertSame(self::GL_ACCOUNT_ID, $this->sentTo('POST', '/' . self::DIVISION . '/salesinvoice/SalesInvoices')[0]['SalesInvoiceLines'][0]['GLAccount']);
        $this->assertArrayNotHasKey('GLAccount', $this->sentTo('POST', '/' . self::DIVISION . '/salesorder/SalesOrders')[0]['SalesOrderLines'][0]);
    }

    public function test_a_line_without_a_price_goes_out_at_zero_and_not_at_the_article_price(): void
    {
        $this->connector()->pushSalesDocument($this->document([new SalesLineData('Gratis filter', 1, 0, 21, article_id: self::ITEM_ID)]), DocumentForm::DraftInvoice);

        $this->assertSame(0.0, $this->sentTo('POST', '/' . self::DIVISION . '/salesinvoice/SalesInvoices')[0]['SalesInvoiceLines'][0]['UnitPrice']);
    }

    public function test_a_login_that_names_no_administration_leaves_nothing_half_made(): void
    {
        $this->connection->delete();
        $this->answers['GET /current/Me'] = fn () => Http::response(['d' => ['results' => [[]]]]);
        $user = $this->userWith('integration.manage');
        $start = $this->actingAs($user)->get('/integrations/exactonline/oauth');
        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->actingAs($user)->get('/integrations/oauth/callback?code=de-code&state=' . $query['state'])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'noemde geen administratie'));

        $this->assertFalse(IntegrationConnection::where('connector', 'exactonline')->exists());
    }

    public function test_starting_to_connect_changes_nothing_yet(): void
    {
        $this->connection->delete();

        $this->actingAs($this->userWith('integration.manage'))->get('/integrations/exactonline/oauth')->assertRedirect();

        $this->assertFalse(IntegrationConnection::where('connector', 'exactonline')->exists());
    }

    public function test_a_busy_token_address_is_worth_another_try_and_not_a_new_login(): void
    {
        $this->connection->storeCredentials(['access_token' => 'token-0', 'refresh_token' => 'refresh-0', 'expires_at' => (string) now()->subMinute()->getTimestamp()]);
        $this->connection->saveQuietly();
        $this->token_failure = [503, []];

        $this->expectException(IntegrationUnavailable::class);

        $this->connector()->test();
    }

    public function test_the_administration_cannot_change_while_records_are_linked(): void
    {
        app(Synchronizer::class)->pull($this->connection->fresh(), Area::Customers);

        $this->actingAs($this->userWith('integration.manage'))->put('/integrations/' . $this->connection->id, [
            'is_active' => true,
            'options' => ['division' => '456'],
        ])->assertSessionHasErrors('options.division');

        $this->assertSame(self::DIVISION, $this->connection->fresh()->option('division'));
    }
}

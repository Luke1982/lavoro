<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Connectors\Afas\AfasConnector;
use App\Domain\Integrations\Data\CustomerData;
use App\Domain\Integrations\Data\SalesDocumentData;
use App\Domain\Integrations\Data\SalesLineData;
use App\Domain\Integrations\DocumentForm;
use App\Domain\Integrations\IntegrationUnavailable;
use App\Domain\Integrations\Sync\Synchronizer;
use App\Exceptions\Refusal;
use App\Models\Customer;
use App\Models\IntegrationConnection;
use App\Models\Material;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AFAS Profit behind the standard shapes, against a fake of its REST service
 * that keeps what was sent.
 */
class AfasConnectorTest extends TestCase
{
    private const ROOT = 'https://12345.rest.afas.online/ProfitRestServices';

    private IntegrationConnection $connection;

    /** @var array<int, Request> */
    private array $sent = [];

    /** @var array<string, \Closure(Request): mixed> */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->answer($request));

        $this->connection = IntegrationConnection::open('afas');
        $this->connection->settings = [...$this->connection->settings, 'customers' => ['read' => true, 'write' => false, 'leading' => 'external']];
        $this->connection->storeCredentials(['environment' => '12345', 'token' => 'GEHEIME-TOKEN']);
        $this->connection->saveQuietly();
    }

    private function answer(Request $request)
    {
        $this->sent[] = $request;
        $key = $request->method() . ' ' . Str::before(Str::after($request->url(), self::ROOT), '?');

        if (isset($this->answers[$key])) {
            return ($this->answers[$key])($request);
        }

        return match ($key) {
            'GET /connectors/Lavoro_Debiteuren' => Http::response(['skip' => 0, 'take' => 500, 'rows' => [$this->debtor('10001', 'Garage Smit')]]),
            'GET /connectors/Lavoro_Artikelen' => Http::response(['rows' => [
                ['ItemCode' => 'FLT', 'Description' => 'Filter', 'SalesPrice' => 12.5, 'Blocked' => false],
                ['ItemCode' => 'OUD', 'Description' => 'Oud', 'SalesPrice' => 1, 'Blocked' => true],
            ]]),
            'GET /metainfo/get/Lavoro_Debiteuren' => Http::response(['name' => 'Lavoro_Debiteuren', 'fields' => array_map(fn ($id) => ['id' => $id],
                ['DebtorId', 'Name', 'Email', 'Phone', 'Website', 'Street', 'PostalCode', 'City', 'CountryId', 'VatNumber', 'CocNumber', 'ModifiedDate'])]),
            'GET /metainfo/get/Lavoro_Artikelen' => Http::response(['name' => 'Lavoro_Artikelen', 'fields' => array_map(fn ($id) => ['id' => $id],
                ['ItemCode', 'Description', 'SalesPrice', 'ModifiedDate'])]),
            'POST /connectors/FbSales' => Http::response(['results' => ['FbSales' => ['OrNu' => '2026-0815']]], 201),
        };
    }

    private function debtor(string $id, string $name): array
    {
        return ['DebtorId' => $id, 'Name' => $name, 'Email' => 'info@smit.nl', 'Street' => 'Kerkweg 3', 'PostalCode' => '1234 AB',
            'City' => 'Ede', 'CountryId' => 'NL', 'VatNumber' => 'NL001', 'CocNumber' => '12345678', 'ModifiedDate' => '2026-11-19T08:00:00Z'];
    }

    private function connector(): AfasConnector
    {
        return $this->connection->fresh()->connector();
    }

    /** @return array<int, Request> */
    private function sentTo(string $method, string $path): array
    {
        return array_values(array_filter($this->sent, fn (Request $request) => $request->method() === $method
            && Str::before(Str::after($request->url(), self::ROOT), '?') === $path));
    }

    public function test_it_logs_in_with_the_token_as_afas_wants_it(): void
    {
        $this->connector()->fetchCustomer('10001');

        $header = $this->sent[0]->header('Authorization')[0];
        $this->assertSame('AfasToken ' . base64_encode('<token><version>1</version><data>GEHEIME-TOKEN</data></token>'), $header);
        $this->assertStringStartsWith(self::ROOT, $this->sent[0]->url());
    }

    public function test_debtors_come_in_page_by_page_sorted_on_their_number(): void
    {
        app(Synchronizer::class)->pull($this->connection->fresh(), Area::Customers);

        $this->assertSame('12345678', Customer::where('name', 'Garage Smit')->value('chamber_of_commerce_number'));
        $first = $this->sentTo('GET', '/connectors/Lavoro_Debiteuren')[0];
        $this->assertEquals([0, 500, 'DebtorId'], [$first['skip'], $first['take'], $first['orderbyfieldids']]);
    }

    public function test_the_quarter_hourly_pull_asks_for_what_changed_in_dutch_time(): void
    {
        iterator_to_array($this->connector()->pullCustomers(CarbonImmutable::parse('2026-11-19 10:15:00', 'UTC')));

        $request = $this->sentTo('GET', '/connectors/Lavoro_Debiteuren')[0];
        $this->assertSame(['ModifiedDate', '2026-11-19T10:15:00', '4'], [$request['filterfieldids'], $request['filtervalues'], $request['operatortypes']]);
    }

    public function test_blocked_articles_stay_out(): void
    {
        $this->connection->settings = [...$this->connection->settings, 'materials' => ['read' => true]];
        $this->connection->saveQuietly();

        app(Synchronizer::class)->pull($this->connection->fresh(), Area::Materials);

        $this->assertTrue(Material::where('code', 'FLT')->exists());
        $this->assertFalse(Material::where('code', 'OUD')->exists());
    }

    public function test_the_test_names_every_field_a_getconnector_is_missing(): void
    {
        try {
            $this->connector()->test();
            $this->fail('An incomplete GetConnector should have been reported.');
        } catch (Refusal $e) {
            $this->assertStringContainsString('Lavoro_Artikelen mist Blocked', $e->getMessage());
            $this->assertStringNotContainsString('Lavoro_Debiteuren', $e->getMessage());
        }
    }

    public function test_a_werkbon_becomes_a_sales_order_with_article_lines(): void
    {
        $pushed = $this->connector()->pushSalesDocument(new SalesDocumentData('Werkbon 12', 'Werkbon 12', CarbonImmutable::parse('2026-11-19'), '10001',
            CustomerData::from(['name' => 'Garage Smit']), [
                new SalesLineData('Filter', 2, 12.5, 21, article_id: 'FLT'),
                new SalesLineData('Los onderdeel', 1, 5, 21),
            ]), DocumentForm::SalesOrder);

        $order = $this->sentTo('POST', '/connectors/FbSales')[0]['FbSales']['Element'];
        $this->assertSame(['DbId' => '10001', 'OrDa' => '2026-11-19'], $order['Fields']);
        $this->assertSame([['Fields' => ['VaIt' => '2', 'ItCd' => 'FLT', 'QuUn' => 2.0, 'Upri' => 12.5]]], $order['Objects'][0]['FbSalesLines']['Element']);
        $this->assertSame('2026-0815', $pushed->number);
        $this->assertSame(['Materiaal zonder artikel in AFAS overgeslagen: Los onderdeel'], $pushed->warnings);
    }

    public function test_what_profit_objects_to_is_read_from_its_header(): void
    {
        $this->answers['POST /connectors/FbSales'] = fn () => Http::response('', 500, ['X-Profit-Error' => base64_encode('Debiteur 10001 is geblokkeerd.')]);

        $this->expectException(Refusal::class);
        $this->expectExceptionMessage('Debiteur 10001 is geblokkeerd.');

        $this->connector()->pushSalesDocument(new SalesDocumentData('Werkbon 12', 'Werkbon 12', CarbonImmutable::now(), '10001',
            CustomerData::from(['name' => 'X']), [new SalesLineData('Filter', 1, 12.5, 21, article_id: 'FLT')]), DocumentForm::SalesOrder);
    }

    public function test_a_wrong_token_is_named_as_such(): void
    {
        $this->answers['GET /connectors/Lavoro_Debiteuren'] = fn () => Http::response('', 401);

        $this->expectException(Refusal::class);
        $this->expectExceptionMessage('weigert de token');

        $this->connector()->fetchCustomer('10001');
    }

    public function test_an_afas_that_is_down_is_worth_another_try_later(): void
    {
        $this->answers['GET /connectors/Lavoro_Debiteuren'] = fn () => Http::response('', 503);

        $this->expectException(IntegrationUnavailable::class);

        $this->connector()->fetchCustomer('10001');
    }

    public function test_a_token_pasted_with_its_xml_around_it_is_not_wrapped_twice(): void
    {
        $this->connection->storeCredentials(['environment' => '12345', 'token' => '<token><version>1</version><data>GEHEIME-TOKEN</data></token>']);
        $this->connection->saveQuietly();

        $this->connector()->fetchCustomer('10001');

        $this->assertSame('AfasToken ' . base64_encode('<token><version>1</version><data>GEHEIME-TOKEN</data></token>'), $this->sent[0]->header('Authorization')[0]);
    }
}

<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Data\CustomerData;
use App\Domain\Integrations\Data\SalesDocumentData;
use App\Domain\Integrations\Data\SalesLineData;
use App\Domain\Integrations\DocumentForm;
use App\Domain\Integrations\IntegrationUnavailable;
use App\Domain\Integrations\Sync\Synchronizer;
use App\Exceptions\Refusal;
use App\Models\Customer;
use App\Models\IntegrationConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SnelStart behind the standard shapes, against a fake of its API that keeps
 * what was sent.
 */
class SnelStartConnectorTest extends TestCase
{
    private IntegrationConnection $connection;

    /** @var array<string, array<string, mixed>> */
    private array $relations = [];

    /** @var array<int, Request> */
    private array $sent = [];

    private ?int $status = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->answer($request));

        $this->connection = new IntegrationConnection([
            'connector' => 'snelstart',
            'is_active' => true,
            'settings' => [...IntegrationConnection::defaultSettings('snelstart'), 'customers' => ['read' => true, 'write' => false]],
        ]);
        $this->connection->storeCredentials(['client_key' => 'client', 'subscription_key' => 'abonnement']);
        $this->connection->save();
    }

    private function answer(Request $request)
    {
        $this->sent[] = $request;
        $path = Str::after(parse_url($request->url(), PHP_URL_PATH), '/v2');

        if (str_contains($request->url(), 'auth.snelstart.nl')) {
            return Http::response(['access_token' => 'token']);
        }

        if ($this->status) {
            return Http::response(['modelState' => ['naam' => ['Is verplicht']]], $this->status);
        }

        return match (true) {
            $path === '/relaties' && $request->method() === 'GET' => Http::response(array_values($this->relations)),
            $path === '/landen' => Http::response([['id' => 'land-nl', 'landcodeISO' => 'NL'], ['id' => 'land-be', 'landcodeISO' => 'BE']]),
            str_starts_with($path, '/landen/') => Http::response(['id' => 'land-nl', 'landcodeISO' => 'NL']),
            str_starts_with($path, '/relaties/') && $request->method() === 'GET' => isset($this->relations[Str::after($path, '/relaties/')])
                ? Http::response($this->relations[Str::after($path, '/relaties/')])
                : Http::response([], 404),
            str_starts_with($path, '/relaties/') && $request->method() === 'PUT' => Http::response($request->data()),
            $path === '/verkooporders' => Http::response(['id' => 'order-1']),
            $path === '/artikelen' => Http::response([]),
        };
    }

    private function relation(string $id, array $fields = []): void
    {
        $this->relations[$id] = [
            'id' => $id,
            'naam' => 'Bakkerij Jansen',
            'relatiesoort' => ['Klant'],
            'betalingstermijn' => 30,
            'vestigingsAdres' => ['straat' => 'Dorpsstraat 1', 'postcode' => '8011 AB', 'plaats' => 'Zwolle', 'land' => ['id' => 'land-nl']],
            'factuurEmailVersturen' => ['shouldSend' => true, 'email' => 'factuur@jansen.nl'],
            ...$fields,
        ];
    }

    private function lastRequest(string $method, string $path): ?Request
    {
        return collect($this->sent)->last(fn (Request $request) => $request->method() === $method
            && str_ends_with(parse_url($request->url(), PHP_URL_PATH), $path));
    }

    public function test_customers_come_in_with_their_addresses_and_without_the_placeholders(): void
    {
        $this->relation('r-1');
        $this->relation('r-2', ['naam' => 'Klant onbekend']);

        app(Synchronizer::class)->pull($this->connection, Area::Customers);

        $customer = Customer::where('name', 'Bakkerij Jansen')->firstOrFail();
        $this->assertSame('8011 AB', $customer->postal_code);
        $this->assertSame('NL', $customer->country);
        $this->assertSame('factuur@jansen.nl', $customer->invoice_email);
        $this->assertFalse(Customer::where('name', 'Klant onbekend')->exists());
    }

    public function test_updating_a_customer_keeps_what_lavoro_does_not_know_about(): void
    {
        $this->relation('r-1');

        $this->connection->connector()->updateCustomer('r-1', CustomerData::from([
            'name' => 'Bakkerij Jansen BV',
            'address' => 'Dorpsstraat 1',
            'postal_code' => '8011 AB',
            'city' => 'Zwolle',
            'country' => 'BE',
            'invoice_email' => 'nieuw@jansen.nl',
        ]));

        $body = $this->lastRequest('PUT', '/relaties/r-1')->data();
        $this->assertSame('Bakkerij Jansen BV', $body['naam']);
        $this->assertSame(30, $body['betalingstermijn']);
        $this->assertSame('land-be', $body['vestigingsAdres']['land']['id']);
        $this->assertTrue($body['factuurEmailVersturen']['shouldSend']);
        $this->assertSame('nieuw@jansen.nl', $body['factuurEmailVersturen']['email']);
    }

    public function test_a_werkbon_becomes_a_sales_order_with_only_the_lines_that_have_an_article(): void
    {
        $document = new SalesDocumentData(
            reference: 'Werkbon 12',
            description: 'Werkbon 12',
            date: CarbonImmutable::parse('2026-11-19'),
            customer_id: 'r-1',
            customer: CustomerData::from(['name' => 'Bakkerij Jansen', 'address' => 'Dorpsstraat 1', 'country' => 'NL']),
            lines: [
                new SalesLineData('Filter', 2, 12.5, 21, article_id: 'art-1'),
                new SalesLineData('Los onderdeel', 1, 5, 21),
            ],
            memo: 'PO-77',
        );

        $pushed = $this->connection->connector()->pushSalesDocument($document, DocumentForm::SalesOrder);

        $body = $this->lastRequest('POST', '/verkooporders')->data();
        $this->assertSame('order-1', $pushed->id);
        $this->assertSame('r-1', $body['relatie']['id']);
        $this->assertSame('Order', $body['procesStatus']);
        $this->assertSame('PO-77', $body['memo']);
        $this->assertCount(1, $body['regels']);
        $this->assertEquals(25.0, $body['totaalExclusiefBtw']);
        $this->assertEquals(30.25, $body['totaalInclusiefBtw']);
        $this->assertSame('land-nl', $body['afleveradres']['land']['id']);
        $this->assertSame(['Materiaal zonder SnelStart-koppeling overgeslagen: Los onderdeel'], $pushed->warnings);
    }

    public function test_a_werkbon_without_any_linked_material_is_refused(): void
    {
        $document = new SalesDocumentData('Werkbon 12', 'Werkbon 12', CarbonImmutable::now(), 'r-1',
            CustomerData::from(['name' => 'X']), [new SalesLineData('Los', 1, 5, 21)]);

        $this->expectException(Refusal::class);

        $this->connection->connector()->pushSalesDocument($document, DocumentForm::SalesOrder);
    }

    public function test_what_snelstart_objects_to_is_passed_on(): void
    {
        $this->status = 400;

        try {
            $this->connection->connector()->test();
            $this->fail('A 400 should have been refused.');
        } catch (Refusal $e) {
            $this->assertStringContainsString('naam: Is verplicht', $e->getMessage());
        }
    }

    public function test_a_busy_snelstart_is_worth_another_try_later(): void
    {
        $this->status = 503;

        $this->expectException(IntegrationUnavailable::class);

        $this->connection->connector()->test();
    }
}

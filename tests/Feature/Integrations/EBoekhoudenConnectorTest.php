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
use App\Models\Material;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A customer's own e-Boekhouden administration, through the same SOAP client
 * the panel uses, but with the codes of the customer's connection -- never the
 * account in .env.
 */
class EBoekhoudenConnectorTest extends TestCase
{
    private IntegrationConnection $connection;

    /** @var array<int, array{operation: string, body: string}> */
    private array $sent = [];

    private bool $down = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.eboekhouden.username' => 'majorlabel-eigen-account']);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->answer($request));

        $this->connection = new IntegrationConnection([
            'connector' => 'eboekhouden',
            'is_active' => true,
            'settings' => [
                ...IntegrationConnection::defaultSettings('eboekhouden'),
                'customers' => ['read' => true, 'write' => false],
                'options' => ['invoice_template' => 'Standaard'],
            ],
        ]);
        $this->connection->storeCredentials(['username' => 'klant', 'security_code_1' => 'een', 'security_code_2' => 'twee']);
        $this->connection->save();
    }

    private function answer(Request $request)
    {
        $operation = trim(Str::afterLast($request->header('SOAPAction')[0], '/'), '"');
        $this->sent[] = ['operation' => $operation, 'body' => $request->body()];

        if ($this->down) {
            return Http::response('<html><body>Service Unavailable</body></html>', 503);
        }

        $result = match ($operation) {
            'OpenSession' => '<ErrorMsg /><SessionID>sessie-1</SessionID>',
            'CloseSession' => null,
            'GetRelaties' => '<ErrorMsg /><Relaties><cRelatie><ID>42</ID><Code>K042</Code><Bedrijf>Garage Smit</Bedrijf>'
                . '<Adres>Kerkweg 3</Adres><Postcode>1234 AB</Postcode><Plaats>Ede</Plaats><Land>NL</Land>'
                . '<Notitie>Belt liever</Notitie><Gb_ID>0</Gb_ID><GeenEmail>0</GeenEmail></cRelatie></Relaties>',
            'UpdateRelatie' => '<ErrorMsg />',
            'GetArtikelen' => '<ErrorMsg /><Artikelen>'
                . '<cArtikel><ArtikelID>7</ArtikelID><ArtikelOmschrijving>Filter</ArtikelOmschrijving><ArtikelCode>FLT</ArtikelCode>'
                . '<InkoopprijsExclBTW>4.10</InkoopprijsExclBTW><VerkoopprijsExclBTW>12.50</VerkoopprijsExclBTW><Actief>true</Actief></cArtikel>'
                . '<cArtikel><ArtikelID>8</ArtikelID><ArtikelOmschrijving>Oud</ArtikelOmschrijving><ArtikelCode>OUD</ArtikelCode>'
                . '<InkoopprijsExclBTW>0</InkoopprijsExclBTW><VerkoopprijsExclBTW>0</VerkoopprijsExclBTW><Actief>false</Actief></cArtikel>'
                . '</Artikelen>',
            'AddFactuur' => '<ErrorMsg /><Factuurnummer>F2026-001</Factuurnummer>',
        };

        return Http::response('<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            . "<{$operation}Response xmlns=\"http://www.e-boekhouden.nl/soap\">"
            . ($result === null ? '' : "<{$operation}Result>{$result}</{$operation}Result>")
            . "</{$operation}Response></soap:Body></soap:Envelope>");
    }

    private function body(string $operation): string
    {
        return collect($this->sent)->last(fn (array $call) => $call['operation'] === $operation)['body'] ?? '';
    }

    private function document(): SalesDocumentData
    {
        return new SalesDocumentData(
            reference: 'Werkbon 12',
            description: 'Werkbon 12',
            date: CarbonImmutable::parse('2026-11-19'),
            customer_id: '42',
            customer: CustomerData::from(['name' => 'Garage Smit']),
            lines: [new SalesLineData('Filter', 2, 12.5, 21, code: 'FLT')],
        );
    }

    public function test_it_logs_in_with_the_customers_own_codes(): void
    {
        $this->connection->connector()->test();

        $this->assertStringContainsString('<Username>klant</Username>', $this->body('OpenSession'));
        $this->assertStringNotContainsString('majorlabel-eigen-account', $this->body('OpenSession'));
    }

    public function test_relations_come_in_as_customers(): void
    {
        app(Synchronizer::class)->pull($this->connection, Area::Customers);

        $customer = Customer::where('name', 'Garage Smit')->firstOrFail();
        $this->assertSame('Ede', $customer->city);
        $this->assertSame('42', $customer->externalId($this->connection));
    }

    public function test_updating_a_relation_writes_back_what_lavoro_does_not_know(): void
    {
        $this->connection->connector()->updateCustomer('42', CustomerData::from(['name' => 'Garage Smit BV', 'city' => 'Ede']));

        $body = $this->body('UpdateRelatie');
        $this->assertStringContainsString('<ID>42</ID>', $body);
        $this->assertStringContainsString('<Code>K042</Code>', $body);
        $this->assertStringContainsString('<Bedrijf>Garage Smit BV</Bedrijf>', $body);
        $this->assertStringContainsString('<Notitie>Belt liever</Notitie>', $body);
        $this->assertLessThan(strpos($body, '<Bedrijf>'), strpos($body, '<Code>'), 'the fields go in the order of the WSDL');
    }

    public function test_only_active_articles_come_in(): void
    {
        app(Synchronizer::class)->pull($this->connection, Area::Materials);

        $this->assertSame(12.5, (float) Material::where('code', 'FLT')->value('price'));
        $this->assertFalse(Material::where('code', 'OUD')->exists());
    }

    public function test_a_draft_invoice_is_made_but_not_booked(): void
    {
        $pushed = $this->connection->connector()->pushSalesDocument($this->document(), DocumentForm::DraftInvoice);

        $body = $this->body('AddFactuur');
        $this->assertSame('F2026-001', $pushed->number);
        $this->assertStringContainsString('<Relatiecode>K042</Relatiecode>', $body);
        $this->assertStringContainsString('<Factuursjabloon>Standaard</Factuursjabloon>', $body);
        $this->assertStringContainsString('<InBoekhoudingPlaatsen>false</InBoekhoudingPlaatsen>', $body);
        $this->assertStringContainsString('<BTWCode>HOOG_VERK_21</BTWCode>', $body);
        $this->assertStringContainsString('<PrijsPerEenheid>12.5</PrijsPerEenheid>', $body);
    }

    public function test_a_final_invoice_is_booked(): void
    {
        $this->connection->connector()->pushSalesDocument($this->document(), DocumentForm::FinalInvoice);

        $this->assertStringContainsString('<InBoekhoudingPlaatsen>true</InBoekhoudingPlaatsen>', $this->body('AddFactuur'));
    }

    public function test_without_an_invoice_template_it_says_what_to_fill_in(): void
    {
        $this->connection->update(['settings' => [...$this->connection->settings, 'options' => []]]);

        $this->expectException(Refusal::class);
        $this->expectExceptionMessage('factuursjabloon');

        $this->connection->fresh()->connector()->pushSalesDocument($this->document(), DocumentForm::DraftInvoice);
    }

    public function test_a_service_that_is_down_is_worth_another_try_later(): void
    {
        $this->down = true;

        $this->expectException(IntegrationUnavailable::class);

        $this->connection->connector()->test();
    }
}

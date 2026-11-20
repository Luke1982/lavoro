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
use App\Models\LedgerAccount;
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
            'GetGrootboekrekeningen' => '<ErrorMsg /><Rekeningen>'
                . '<cGrootboekrekening><ID>301</ID><Code>8010</Code><Omschrijving>Omzet service</Omschrijving><Categorie>VW</Categorie><Groep>OMZ</Groep></cGrootboekrekening>'
                . '<cGrootboekrekening><ID>302</ID><Code>1100</Code><Omschrijving>Bank</Omschrijving><Categorie>FIN</Categorie><Groep>LIQ</Groep></cGrootboekrekening>'
                . '</Rekeningen>',
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

    public function test_only_accounts_on_the_profit_and_loss_account_come_in(): void
    {
        $this->connection->update(['settings' => [...$this->connection->settings, 'ledger_accounts' => ['read' => true, 'write' => false, 'leading' => 'external']]]);

        app(Synchronizer::class)->pull($this->connection->fresh(), Area::LedgerAccounts);

        $this->assertSame(['8010'], LedgerAccount::pluck('code')->all());
        $this->assertSame('301', LedgerAccount::firstOrFail()->externalId($this->connection));
    }

    public function test_an_invoice_line_is_booked_on_the_number_of_its_own_account(): void
    {
        $document = new SalesDocumentData('Werkbon 12', 'Werkbon 12', CarbonImmutable::parse('2026-11-19'), '42', CustomerData::from(['name' => 'Garage Smit']), [
            new SalesLineData('Filter', 1, 12.5, 21, ledger_account_id: '301'),
            new SalesLineData('Bout', 1, 1, 21),
            new SalesLineData('Pakking', 1, 2, 21, ledger_account_id: '999'),
        ]);

        $pushed = $this->connection->connector()->pushSalesDocument($document, DocumentForm::DraftInvoice);

        preg_match_all('~<TegenrekeningCode>(\d+)</TegenrekeningCode>~', $this->body('AddFactuur'), $accounts);
        $this->assertSame(['8010', '8000', '8000'], $accounts[1]);
        $this->assertSame(['Pakking ging op de standaard omzetrekening: de eigen grootboekrekening staat niet meer in e-Boekhouden.'], $pushed->warnings);
    }

    public function test_a_service_that_is_down_is_worth_another_try_later(): void
    {
        $this->down = true;

        $this->expectException(IntegrationUnavailable::class);

        $this->connection->connector()->test();
    }
}

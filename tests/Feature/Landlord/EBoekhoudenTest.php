<?php

namespace Tests\Feature\Landlord;

use App\Models\Central\Invoice;
use App\Models\Tenant;
use App\Services\Invoicer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MakesLandlordData;
use Tests\TestCase;

/**
 * Booking the panel's invoices in e-Boekhouden, and linking a customer to the
 * relation that is already there instead of creating a second one.
 *
 * Against a fake of the SOAP service that answers per operation and keeps what
 * was sent, so the envelopes themselves can be checked.
 */
class EBoekhoudenTest extends TestCase
{
    use MakesLandlordData;

    /** @var array<int, array<string, string>> The relations e-Boekhouden has. */
    private array $relations = [];

    /** @var array<string, string> Invoice number => relation code, already booked there. */
    private array $booked = [];

    private ?string $refusal = null;

    private ?string $lookup_refusal = null;

    /** @var array<int, array{operation: string, body: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
        $this->actingAs($this->landlord(), 'landlord');

        config([
            'services.eboekhouden.username' => 'majorlabel',
            'services.eboekhouden.security_code_1' => 'code-1',
            'services.eboekhouden.security_code_2' => 'code-2',
        ]);

        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->answer($request));
    }

    private function answer(Request $request)
    {
        $operation = trim(Str::afterLast($request->header('SOAPAction')[0], '/'), '"');
        $body = $request->body();
        $this->sent[] = ['operation' => $operation, 'body' => $body];
        $field = fn (string $name) => preg_match("/<{$name}>(.*?)<\/{$name}>/s", $body, $m) ? html_entity_decode($m[1]) : '';

        $result = match ($operation) {
            'OpenSession' => '<ErrorMsg /><SessionID>sessie-1</SessionID>',
            'CloseSession' => null,
            'GetRelaties' => '<ErrorMsg />' . $this->list('Relaties', collect($this->relations)
                ->filter(fn (array $relation) => $field('Code') !== ''
                    ? $relation['Code'] === $field('Code')
                    : collect($relation)->contains(fn ($value) => Str::contains(Str::lower($value), Str::lower($field('Trefwoord')))))
                ->map(fn (array $relation) => '<cRelatie>' . collect($relation)
                    ->map(fn ($value, $key) => "<{$key}>" . e($value) . "</{$key}>")->implode('') . '</cRelatie>')
                ->implode('')),
            'AddRelatie' => '<ErrorMsg /><Rel_ID>77</Rel_ID>',
            'GetMutaties' => $this->lookup_refusal
                ? '<ErrorMsg><LastErrorCode>E0001</LastErrorCode><LastErrorDescription>' . e($this->lookup_refusal)
                    . '</LastErrorDescription></ErrorMsg>'
                : '<ErrorMsg />' . $this->list('Mutaties', collect($this->booked)
                    ->filter(fn ($relation, $number) => $number === $field('Factuurnummer'))
                    ->map(fn ($relation, $number) => '<cMutatieList><MutatieNr>1</MutatieNr>'
                        . "<RelatieCode>{$relation}</RelatieCode><Factuurnummer>{$number}</Factuurnummer></cMutatieList>")
                    ->implode('')),
            'AddMutatie' => $this->refusal
                ? '<ErrorMsg><LastErrorCode>E0016</LastErrorCode><LastErrorDescription>' . e($this->refusal)
                    . '</LastErrorDescription></ErrorMsg><Mutatienummer>0</Mutatienummer>'
                : '<ErrorMsg /><Mutatienummer>501</Mutatienummer>',
        };

        return Http::response('<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            . "<{$operation}Response xmlns=\"http://www.e-boekhouden.nl/soap\">"
            . ($result === null ? '' : "<{$operation}Result>{$result}</{$operation}Result>")
            . "</{$operation}Response></soap:Body></soap:Envelope>");
    }

    /** An empty result leaves out the element around the list altogether, so the client must cope with that. */
    private function list(string $tag, string $items): string
    {
        return $items === '' ? '' : "<{$tag}>{$items}</{$tag}>";
    }

    /** @return array<int, string> */
    private function operations(): array
    {
        return array_column($this->sent, 'operation');
    }

    private function sentBody(string $operation): string
    {
        return collect($this->sent)->firstWhere('operation', $operation)['body'] ?? '';
    }

    private function relation(array $fields): void
    {
        $this->relations[] = [
            'ID' => (string) (count($this->relations) + 1), 'Code' => '', 'Bedrijf' => '', 'Plaats' => '',
            'Email' => '', 'BTWNummer' => '', 'KvkNummer' => '', ...$fields,
        ];
    }

    private function customer(array $attributes = []): Tenant
    {
        return $this->tenantRow([
            'name' => 'Spee Totaaltechniek',
            'subscription_started_on' => '2026-11-01',
            'eboekhouden_relation_code' => 'ACC1042',
            ...$attributes,
        ]);
    }

    private function book(Invoice $invoice)
    {
        return $this->post("/beheer/{$invoice->tenant_id}/facturen/{$invoice->id}/e-boekhouden");
    }

    /** @return array<int, array<string, float|string>> */
    private function bookedLines(): array
    {
        preg_match_all('/<cMutatieRegel>(.*?)<\/cMutatieRegel>/s', $this->sentBody('AddMutatie'), $lines);

        return array_map(function (string $line) {
            preg_match_all('/<(\w+)>([^<]*)<\/\1>/', $line, $fields, PREG_SET_ORDER);

            return collect($fields)->mapWithKeys(fn ($field) => [$field[1] => $field[2]])->all();
        }, $lines[1]);
    }

    public function test_an_invoice_is_booked_line_by_line_and_adds_up_to_the_cent(): void
    {
        $tenant = $this->customer(['billing_period' => 'yearly', 'extra_office_seats' => 3]);
        $invoice = (new Invoicer($tenant))->issue();

        $this->book($invoice)->assertSessionHas('status');

        $invoice->refresh();
        $this->assertNotNull($invoice->booked_at);
        $this->assertSame('501', $invoice->booking_number);

        $body = $this->sentBody('AddMutatie');
        $this->assertStringContainsString('<Soort>FactuurVerstuurd</Soort>', $body);
        $this->assertStringContainsString('<RelatieCode>ACC1042</RelatieCode>', $body);
        $this->assertStringContainsString("<Factuurnummer>{$invoice->number}</Factuurnummer>", $body);
        $this->assertStringContainsString('<InExBTW>EX</InExBTW>', $body);

        $lines = $this->bookedLines();

        $this->assertCount($invoice->lines->count() + 1, $lines, 'de regels plus de jaarkorting');
        $this->assertSame($invoice->total_cents, (int) round(array_sum(array_column($lines, 'BedragExclBTW')) * 100));
        $this->assertSame($invoice->vat_cents, (int) round(array_sum(array_column($lines, 'BedragBTW')) * 100));
        $this->assertSame($invoice->gross_cents, (int) round(array_sum(array_column($lines, 'BedragInclBTW')) * 100));
        $this->assertSame(['HOOG_VERK_21'], array_values(array_unique(array_column($lines, 'BTWCode'))));
        $this->assertSame(['8000'], array_values(array_unique(array_column($lines, 'TegenrekeningCode'))));
    }

    /** A .NET service ignores fields out of order, so the order is part of the contract. */
    public function test_the_booking_is_written_in_the_order_of_the_wsdl(): void
    {
        $invoice = (new Invoicer($this->customer()))->issue();

        $this->book($invoice);

        $order = ['MutatieNr', 'Soort', 'Datum', 'Rekening', 'RelatieCode', 'Factuurnummer', 'Boekstuk',
            'Omschrijving', 'Betalingstermijn', 'Betalingskenmerk', 'InExBTW', 'MutatieRegels'];
        preg_match('/<oMut>(.*)<\/oMut>/s', $this->sentBody('AddMutatie'), $mutation);
        preg_match_all('/<(\w+)>/', preg_replace('/<MutatieRegels>.*<\/MutatieRegels>/s', '<MutatieRegels></MutatieRegels>', $mutation[1]), $tags);

        $this->assertSame($order, $tags[1]);
        $this->assertSame(
            ['BedragInvoer', 'BedragExclBTW', 'BedragBTW', 'BedragInclBTW', 'BTWCode', 'BTWPercentage', 'TegenrekeningCode', 'KostenplaatsID'],
            array_keys($this->bookedLines()[0]),
        );
    }

    /** The answer to an earlier attempt got lost, but the booking arrived. */
    public function test_an_invoice_that_is_already_there_is_not_booked_twice(): void
    {
        $invoice = (new Invoicer($this->customer()))->issue();
        $invoice->update(['booking_error' => 'Geen antwoord van e-Boekhouden.']);
        $this->booked[$invoice->number] = 'ACC1042';

        $this->book($invoice)->assertSessionHas('status');

        $this->assertNotContains('AddMutatie', $this->operations());
        $this->assertNotNull($invoice->fresh()->booked_at);
    }

    /**
     * After the year was started over, the number can be in e-Boekhouden for
     * a different invoice of another customer. That one is not taken for it.
     */
    public function test_a_number_booked_on_another_relation_is_refused(): void
    {
        $invoice = (new Invoicer($this->customer()))->issue();
        $this->booked[$invoice->number] = 'ACC7777';

        $this->book($invoice)->assertSessionHas('error', fn ($message) => str_contains($message, 'ACC7777'));

        $this->assertNotContains('AddMutatie', $this->operations());
        $this->assertNull($invoice->fresh()->booked_at);
    }

    /**
     * After the year was started over, the same customer usually gets the
     * same number again. This invoice was never sent, so what is there is the
     * old one.
     */
    public function test_a_number_already_there_for_an_invoice_never_sent_is_refused(): void
    {
        $invoice = (new Invoicer($this->customer()))->issue();
        $this->booked[$invoice->number] = 'ACC1042';

        $this->book($invoice)->assertSessionHas('error', fn ($message) => str_contains($message, 'nooit heen gestuurd'));

        $this->assertNotContains('AddMutatie', $this->operations());
        $this->assertNull($invoice->fresh()->booked_at);
    }

    /** Looking it up sends nothing, so a failure there does not count as an invoice that may have gone out. */
    public function test_a_failed_lookup_is_not_kept_as_a_failed_booking(): void
    {
        $invoice = (new Invoicer($this->customer()))->issue();
        $this->lookup_refusal = 'Even niet bereikbaar';

        $this->book($invoice)->assertSessionHas('error', fn ($message) => str_contains($message, 'Even niet bereikbaar'));

        $this->assertNotContains('AddMutatie', $this->operations());
        $this->assertNull($invoice->fresh()->booking_error);
        $this->delete("/beheer/{$invoice->tenant_id}/facturen/{$invoice->id}")->assertSessionHas('status');
    }

    public function test_booking_needs_a_linked_relation(): void
    {
        $invoice = (new Invoicer($this->customer(['eboekhouden_relation_code' => null])))->issue();

        $this->book($invoice)->assertSessionHas('error');

        $this->assertSame([], $this->sent);
        $this->assertNull($invoice->fresh()->booked_at);
    }

    /** The reason e-Boekhouden gives is the one thing that helps; it stays with the invoice. */
    public function test_a_refusal_is_shown_and_kept_and_the_session_still_closed(): void
    {
        $invoice = (new Invoicer($this->customer()))->issue();
        $this->refusal = 'Relatie bestaat niet';

        $this->book($invoice)->assertSessionHas('error', fn ($message) => str_contains($message, 'Relatie bestaat niet'));

        $invoice->refresh();
        $this->assertNull($invoice->booked_at);
        $this->assertStringContainsString('Relatie bestaat niet', $invoice->booking_error);
        $this->assertSame('CloseSession', last($this->operations()));

        /** Whether it arrived is not certain, so its number must not be handed out again. */
        $this->delete("/beheer/{$invoice->tenant_id}/facturen/{$invoice->id}")->assertSessionHas('error');
    }

    /** In the bookkeeping is out of the building: from then on, a credit note. */
    public function test_a_booked_invoice_is_credited_and_not_deleted(): void
    {
        $tenant = $this->customer();
        $invoice = (new Invoicer($tenant))->issue();
        $this->book($invoice);

        $this->delete("/beheer/{$tenant->id}/facturen/{$invoice->id}")->assertSessionHas('error');
        $this->assertNotNull(Invoice::on('central')->find($invoice->id));

        $this->post("/beheer/{$tenant->id}/facturen/{$invoice->id}/crediteren")->assertSessionHas('status');
        $note = Invoice::on('central')->where('credits_invoice_id', $invoice->id)->firstOrFail();
        $this->sent = [];

        $this->book($note)->assertSessionHas('status');

        $body = $this->sentBody('AddMutatie');
        $this->assertStringContainsString('<Omschrijving>Creditfactuur voor ' . $invoice->number . '</Omschrijving>', $body);
        $this->assertSame($note->gross_cents, (int) round(array_sum(array_column($this->bookedLines(), 'BedragInclBTW')) * 100));
        $this->assertLessThan(0, $note->gross_cents);
    }

    public function test_a_customer_is_linked_to_a_relation_that_exists(): void
    {
        $tenant = $this->customer(['eboekhouden_relation_code' => null]);
        $this->relation(['Code' => 'ACC1042', 'Bedrijf' => 'Spee Totaaltechniek B.V.']);

        $this->post("/beheer/{$tenant->id}/e-boekhouden/relatie", ['code' => 'ACC9999'])->assertSessionHas('error');
        $this->assertNull($tenant->fresh()->eboekhouden_relation_code);

        $this->post("/beheer/{$tenant->id}/e-boekhouden/relatie", ['code' => 'ACC1042'])->assertSessionHas('status');
        $this->assertSame('ACC1042', $tenant->fresh()->eboekhouden_relation_code);

        $this->delete("/beheer/{$tenant->id}/e-boekhouden/relatie")->assertSessionHas('status');
        $this->assertNull($tenant->fresh()->eboekhouden_relation_code);
    }

    /** @return array<string, array{array<string, string>, array<string, string>}> */
    public static function sameCompany(): array
    {
        return [
            'zelfde KvK-nummer' => [['coc_number' => '12345678'], ['Bedrijf' => 'Anders genaamd', 'KvkNummer' => '12345678']],
            'zelfde btw-nummer' => [['vat_number' => 'NL001234567B01'], ['Bedrijf' => 'Anders genaamd', 'BTWNummer' => 'nl001234567b01']],
            'zelfde e-mailadres' => [['invoice_email' => 'factuur@spee.nl'], ['Bedrijf' => 'Anders genaamd', 'Email' => 'Factuur@Spee.nl ']],
            'zelfde naam met rechtsvorm' => [[], ['Bedrijf' => 'Spee Totaaltechniek B.V.']],
            'zelfde naam, hier met rechtsvorm' => [['name' => 'Spee Totaaltechniek B.V.'], ['Bedrijf' => 'Spee Totaaltechniek']],
            'KvK-nummer met spaties' => [['coc_number' => '1234 5678'], ['Bedrijf' => 'Anders genaamd', 'KvkNummer' => '12345678']],
        ];
    }

    #[DataProvider('sameCompany')]
    public function test_no_second_relation_is_created_for_the_same_company(array $customer, array $remote): void
    {
        $tenant = $this->customer(['eboekhouden_relation_code' => null, ...$customer]);
        $this->relation(['Code' => 'ACC1042', ...$remote]);

        $this->post("/beheer/{$tenant->id}/e-boekhouden/relatie/nieuw")
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'ACC1042'));

        $this->assertNotContains('AddRelatie', $this->operations());
        $this->assertNull($tenant->fresh()->eboekhouden_relation_code);
    }

    /** "Spee" is not "Speelgoed": a search hit that is not the same company does not block. */
    public function test_a_merely_similar_name_does_not_block_a_new_relation(): void
    {
        $tenant = $this->customer([
            'eboekhouden_relation_code' => null, 'name' => 'Spee', 'coc_number' => '12345678',
            'invoice_address' => 'Straatweg 1', 'invoice_city' => 'Zwolle',
        ]);
        $this->relation(['Code' => 'ACC2000', 'Bedrijf' => 'Speelgoed BV', 'KvkNummer' => '87654321']);

        $this->post("/beheer/{$tenant->id}/e-boekhouden/relatie/nieuw")->assertSessionHas('status');

        $code = $tenant->fresh()->eboekhouden_relation_code;
        $this->assertStringStartsWith('LVR-', $code);

        $body = $this->sentBody('AddRelatie');
        $this->assertStringContainsString("<Code>{$code}</Code>", $body);
        $this->assertStringContainsString('<Bedrijf>Spee</Bedrijf>', $body);
        $this->assertStringContainsString('<KvkNummer>12345678</KvkNummer>', $body);
        $this->assertMatchesRegularExpression('/<ID>0<\/ID><AddDatum>[^<]+<\/AddDatum><Code>/', $body);
    }

    public function test_the_search_comes_back_on_the_subscription_screen(): void
    {
        $tenant = $this->customer(['eboekhouden_relation_code' => null]);
        $this->relation(['Code' => 'ACC1042', 'Bedrijf' => 'Spee Totaaltechniek B.V.', 'Plaats' => 'Zwolle']);
        $this->relation(['Code' => 'ACC1043', 'Bedrijf' => 'Bakkerij Jansen']);

        $this->from("/beheer/{$tenant->id}")
            ->post("/beheer/{$tenant->id}/e-boekhouden/zoeken", ['term' => 'spee'])
            ->assertRedirect("/beheer/{$tenant->id}");

        $this->get("/beheer/{$tenant->id}")->assertInertia(fn ($page) => $page
            ->where('eboekhouden.configured', true)
            ->where('eboekhouden.term', 'spee')
            ->has('eboekhouden.matches', 1)
            ->where('eboekhouden.matches.0.code', 'ACC1042')
            ->where('eboekhouden.matches.0.city', 'Zwolle'));
    }

    public function test_without_credentials_the_panel_says_so_and_sends_nothing(): void
    {
        config(['services.eboekhouden.username' => null]);
        $tenant = $this->customer();

        $this->get("/beheer/{$tenant->id}")->assertInertia(fn ($page) => $page->where('eboekhouden.configured', false));
        $this->book((new Invoicer($tenant))->issue())->assertSessionHas('error');

        $this->assertSame([], $this->sent);
    }
}

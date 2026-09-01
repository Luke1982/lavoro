<?php

namespace Tests\Feature\Licensing;

use App\Models\Central\Invoice;
use App\Models\Tenant;
use App\Services\InvoiceUbl;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Het UBL-bestand gaat het boekhoudpakket van de klant in. Klopt de optelling
 * niet, dan staat er een verkeerd bedrag in andermans administratie -- en dat
 * merkt niemand aan een foutmelding.
 */
class InvoiceUblTest extends TestCase
{
    private function invoice(): Invoice
    {
        $id = 'ubl-' . uniqid();

        DB::connection('central')->table('tenants')->insert([
            'id' => $id,
            'name' => 'Boekhoudklant BV',
            'invoice_address' => 'Industrieweg 5',
            'invoice_postcode' => '3542 AD',
            'invoice_city' => 'Utrecht',
            'vat_number' => 'NL009876543B01',
            'data' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoice = Invoice::on('central')->create([
            'number' => 'UBL-' . uniqid(),
            'tenant_id' => $id,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'issued_on' => '2026-03-01',
            'due_on' => '2026-03-31',
            'subtotal_cents' => 18250,
            'discount_cents' => 0,
            'total_cents' => 18250,
            'vat_percent' => 21,
            'vat_cents' => 3833,
            'gross_cents' => 22083,
        ]);

        $invoice->lines()->create(['description' => 'Abonnement', 'kind' => 'subscription', 'amount_cents' => 16000]);
        $invoice->lines()->create(['description' => 'AI-assistent', 'kind' => 'module', 'amount_cents' => 2250]);

        return $invoice->load('lines');
    }

    private function xml(Invoice $invoice): \SimpleXMLElement
    {
        $tenant = Tenant::on('central')->findOrFail($invoice->tenant_id);
        $raw = (new InvoiceUbl($invoice, $tenant))->toXml();

        $document = simplexml_load_string($raw);
        $this->assertNotFalse($document, 'Het bestand is geen geldige XML.');

        $document->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $document->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

        return $document;
    }

    public function test_the_totals_add_up(): void
    {
        $invoice = $this->invoice();
        $xml = $this->xml($invoice);

        $net = (float) (string) $xml->xpath('//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount')[0];
        $gross = (float) (string) $xml->xpath('//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount')[0];
        $vat = (float) (string) $xml->xpath('//cac:TaxTotal/cbc:TaxAmount')[0];

        $this->assertSame(182.50, $net);
        $this->assertSame(38.33, $vat);
        $this->assertSame(220.83, $gross);
        $this->assertSame(round($net + $vat, 2), $gross, 'Netto plus btw hoort het totaal te zijn.');
    }

    public function test_every_line_is_in_the_file(): void
    {
        $invoice = $this->invoice();
        $xml = $this->xml($invoice);

        $lines = $xml->xpath('//cac:InvoiceLine');

        $this->assertCount(2, $lines);

        $amounts = array_map(fn ($line) => (float) (string) $line->xpath('cbc:LineExtensionAmount')[0], $lines);

        $this->assertSame(182.50, round(array_sum($amounts), 2), 'De regels tellen niet op tot het nettobedrag.');
    }

    public function test_the_numbers_use_the_notation_an_accounting_package_reads(): void
    {
        $xml = $this->xml($this->invoice());

        foreach ($xml->xpath('//cbc:TaxExclusiveAmount | //cbc:TaxInclusiveAmount | //cbc:LineExtensionAmount') as $amount) {
            $this->assertMatchesRegularExpression(
                '/^-?\d+\.\d{2}$/',
                (string) $amount,
                'Een komma of duizendtal maakt het bestand onleesbaar.',
            );
        }
    }

    public function test_it_names_the_invoice_and_the_customer(): void
    {
        $invoice = $this->invoice();
        $xml = $this->xml($invoice);

        $this->assertSame($invoice->number, (string) $xml->xpath('//cbc:ID')[0]);
        $this->assertSame('2026-03-01', (string) $xml->xpath('//cbc:IssueDate')[0]);
        $this->assertSame('2026-03-31', (string) $xml->xpath('//cbc:DueDate')[0]);
        $this->assertStringContainsString('Boekhoudklant BV', (string) $xml->asXML());
    }
}

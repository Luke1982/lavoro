<?php

namespace Tests\Feature\Licensing;

use App\Models\Central\Invoice;
use App\Models\Tenant;
use App\Services\SepaDirectDebit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Het incassobestand gaat naar de bank. Gaat er iets mis, dan hoor je dat
 * dagen later terug als een afgekeurde batch, niet als een foutmelding hier.
 * Vandaar dat dit bestand tot op de knoop wordt nagelopen.
 */
class SepaDirectDebitTest extends TestCase
{
    private function tenantWithMandate(array $attributes = []): Tenant
    {
        $id = 'sepa-' . uniqid();

        DB::connection('central')->table('tenants')->insert(array_merge([
            'id' => $id,
            'name' => 'Incassoklant BV',
            'account_holder' => 'Incassoklant BV',
            'iban' => 'NL39ASNB0932392881',
            'bic' => 'ASNBNL21',
            'mandate_reference' => 'LVR-001',
            'mandate_signed_on' => '2026-01-15',
            'payment_method' => 'direct_debit',
            'data' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return Tenant::on('central')->findOrFail($id);
    }

    private function invoiceFor(Tenant $tenant, int $gross_cents, string $number): Invoice
    {
        return Invoice::on('central')->create([
            'number' => $number,
            'tenant_id' => $tenant->id,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'issued_on' => '2026-03-01',
            'due_on' => '2026-03-31',
            'subtotal_cents' => $gross_cents,
            'total_cents' => $gross_cents,
            'vat_percent' => 21,
            'vat_cents' => 0,
            'gross_cents' => $gross_cents,
        ]);
    }

    private function xml(iterable $invoices): \SimpleXMLElement
    {
        $raw = (new SepaDirectDebit(
            collect($invoices),
            CarbonImmutable::parse('2026-04-10'),
            'LVR-TEST',
        ))->toXml();

        $document = simplexml_load_string($raw);

        $this->assertNotFalse($document, 'Het bestand is geen geldige XML.');

        $document->registerXPathNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02');

        return $document;
    }

    public function test_it_is_the_format_the_bank_asks_for(): void
    {
        $tenant = $this->tenantWithMandate();
        $invoice = $this->invoiceFor($tenant, 23175, 'T-1');

        $xml = $this->xml([$invoice->setRelation('tenant', $tenant)]);

        $this->assertSame(
            'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02',
            (string) $xml->getDocNamespaces()[''],
        );
        $this->assertSame('DD', (string) $xml->xpath('//p:PmtMtd')[0]);
        $this->assertSame('CORE', (string) $xml->xpath('//p:LclInstrm/p:Cd')[0]);
        $this->assertSame('2026-04-10', (string) $xml->xpath('//p:ReqdColltnDt')[0]);
    }

    public function test_the_amounts_use_the_notation_a_bank_reads(): void
    {
        $tenant = $this->tenantWithMandate();
        $invoice = $this->invoiceFor($tenant, 123450, 'T-2');

        $xml = $this->xml([$invoice->setRelation('tenant', $tenant)]);

        $amount = (string) $xml->xpath('//p:InstdAmt')[0];

        $this->assertSame('1234.50', $amount, 'Een komma of duizendtal maakt het bestand onbruikbaar.');
        $this->assertSame('EUR', (string) $xml->xpath('//p:InstdAmt/@Ccy')[0]);
    }

    /** De optelling moet kloppen, anders weigert de bank de hele batch. */
    public function test_the_totals_match_the_transactions(): void
    {
        $tenant = $this->tenantWithMandate();

        $invoices = collect([
            $this->invoiceFor($tenant, 10000, 'T-3')->setRelation('tenant', $tenant),
            $this->invoiceFor($tenant, 2550, 'T-4')->setRelation('tenant', $tenant),
        ]);

        $xml = $this->xml($invoices);

        $this->assertSame('2', (string) $xml->xpath('//p:GrpHdr/p:NbOfTxs')[0]);
        $this->assertSame('125.50', (string) $xml->xpath('//p:GrpHdr/p:CtrlSum')[0]);

        $amounts = array_map('floatval', array_map('strval', $xml->xpath('//p:InstdAmt')));
        $this->assertSame(125.50, round(array_sum($amounts), 2));
    }

    /**
     * Een tweede incasso als "eerste" wordt geweigerd. Binnen één bestand is
     * dus alleen de eerste van een machtiging FRST.
     */
    public function test_only_the_first_collection_for_a_mandate_is_a_first(): void
    {
        $tenant = $this->tenantWithMandate();

        $invoices = collect([
            $this->invoiceFor($tenant, 1000, 'T-5')->setRelation('tenant', $tenant),
            $this->invoiceFor($tenant, 2000, 'T-6')->setRelation('tenant', $tenant),
        ]);

        $xml = $this->xml($invoices);

        $sequences = array_map('strval', $xml->xpath('//p:SeqTp'));

        sort($sequences);
        $this->assertSame(['FRST', 'RCUR'], $sequences);
    }

    public function test_the_mandate_travels_with_every_transaction(): void
    {
        $tenant = $this->tenantWithMandate();
        $invoice = $this->invoiceFor($tenant, 5000, 'T-7');

        $xml = $this->xml([$invoice->setRelation('tenant', $tenant)]);

        $this->assertSame('LVR-001', (string) $xml->xpath('//p:MndtId')[0]);
        $this->assertSame('2026-01-15', (string) $xml->xpath('//p:DtOfSgntr')[0]);
        $this->assertSame('NL39ASNB0932392881', (string) $xml->xpath('//p:DbtrAcct/p:Id/p:IBAN')[0]);
        $this->assertSame('T-7', (string) $xml->xpath('//p:EndToEndId')[0]);
    }

    /** Spaties in een IBAN zijn gebruikelijk op papier en verboden in het bestand. */
    public function test_a_spaced_iban_is_written_without_spaces(): void
    {
        $tenant = $this->tenantWithMandate(['iban' => 'NL39 ASNB 0932 3928 81']);
        $invoice = $this->invoiceFor($tenant, 1000, 'T-8');

        $xml = $this->xml([$invoice->setRelation('tenant', $tenant)]);

        $this->assertSame('NL39ASNB0932392881', (string) $xml->xpath('//p:DbtrAcct/p:Id/p:IBAN')[0]);
    }
}

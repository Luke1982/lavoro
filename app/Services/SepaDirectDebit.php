<?php

namespace App\Services;

use App\Models\Central\Invoice;
use App\Models\Central\IssuerSetting;
use App\Models\Tenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use XMLWriter;

/**
 * Een incassobestand in SEPA-XML, pain.008.001.02. Dat is wat ASN via Online
 * Bankieren inleest; hun handleiding noemt het "SEPA XML-formaat".
 *
 * Eén bestand kan meerdere batches bevatten. Er wordt er hier één per soort
 * gemaakt — eerste incasso en doorlopende incasso mogen niet door elkaar in
 * dezelfde batch, en de bank weigert het bestand als dat toch gebeurt.
 */
class SepaDirectDebit
{
    public const MAX_REMITTANCE = 140;

    public function __construct(
        private Collection $invoices,
        private CarbonImmutable $collect_on,
        private string $batch_id,
    ) {}

    public function toXml(): string
    {
        $issuer = IssuerSetting::all_values();

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('Document');
        $xml->writeAttribute('xmlns', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02');
        $xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        $xml->startElement('CstmrDrctDbtInitn');

        $this->groupHeader($xml, $issuer);

        /**
         * FRST voor wie nog niet eerder is geïncasseerd, RCUR daarna. De bank
         * kijkt hierop; een tweede incasso als FRST wordt geweigerd. Ook
         * binnen dit ene bestand: van twee facturen van dezelfde klant is
         * alleen de eerste een eerste.
         */
        $seen = [];
        $sequences = [];

        foreach ($this->invoices as $invoice) {
            $first = !in_array($invoice->tenant_id, $seen, true) && $this->neverCollected($invoice);
            $sequences[$invoice->id] = $first ? 'FRST' : 'RCUR';
            $seen[] = $invoice->tenant_id;
        }

        foreach ($this->invoices->groupBy(fn ($invoice) => $sequences[$invoice->id]) as $sequence => $group) {
            $this->paymentInformation($xml, $issuer, (string) $sequence, $group);
        }

        $xml->endElement();
        $xml->endElement();

        return $xml->outputMemory();
    }

    private function groupHeader(XMLWriter $xml, array $issuer): void
    {
        $xml->startElement('GrpHdr');
        $xml->writeElement('MsgId', $this->batch_id);
        $xml->writeElement('CreDtTm', now()->format('Y-m-d\TH:i:s'));
        $xml->writeElement('NbOfTxs', (string) $this->invoices->count());
        $xml->writeElement('CtrlSum', Money::machine($this->invoices->sum('gross_cents')));

        $xml->startElement('InitgPty');
        $xml->writeElement('Nm', $issuer['name'] ?? 'MajorLabel');
        $xml->endElement();

        $xml->endElement();
    }

    private function paymentInformation(XMLWriter $xml, array $issuer, string $sequence, Collection $group): void
    {
        $xml->startElement('PmtInf');
        $xml->writeElement('PmtInfId', $this->batch_id . '-' . strtolower($sequence));
        $xml->writeElement('PmtMtd', 'DD');
        $xml->writeElement('BtchBookg', 'true');
        $xml->writeElement('NbOfTxs', (string) $group->count());
        $xml->writeElement('CtrlSum', Money::machine($group->sum('gross_cents')));

        $xml->startElement('PmtTpInf');
        $xml->startElement('SvcLvl');
        $xml->writeElement('Cd', 'SEPA');
        $xml->endElement();
        $xml->startElement('LclInstrm');
        $xml->writeElement('Cd', 'CORE');
        $xml->endElement();
        $xml->writeElement('SeqTp', $sequence);
        $xml->endElement();

        $xml->writeElement('ReqdColltnDt', $this->collect_on->toDateString());

        $xml->startElement('Cdtr');
        $xml->writeElement('Nm', $issuer['name'] ?? 'MajorLabel');
        $xml->endElement();

        $xml->startElement('CdtrAcct');
        $xml->startElement('Id');
        $xml->writeElement('IBAN', $this->plain($issuer['iban'] ?? ''));
        $xml->endElement();
        $xml->endElement();

        $xml->startElement('CdtrAgt');
        $xml->startElement('FinInstnId');
        $xml->writeElement('BIC', $this->plain($issuer['bic'] ?? ''));
        $xml->endElement();
        $xml->endElement();

        $xml->writeElement('ChrgBr', 'SLEV');

        /** Het incassant-ID dat de bank heeft uitgegeven, niet het KvK-nummer. */
        $xml->startElement('CdtrSchmeId');
        $xml->startElement('Id');
        $xml->startElement('PrvtId');
        $xml->startElement('Othr');
        $xml->writeElement('Id', $this->plain($issuer['incassant_id'] ?? ''));
        $xml->startElement('SchmeNm');
        $xml->writeElement('Prtry', 'SEPA');
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();

        foreach ($group as $invoice) {
            $this->transaction($xml, $invoice, $invoice->tenant);
        }

        $xml->endElement();
    }

    private function transaction(XMLWriter $xml, Invoice $invoice, Tenant $tenant): void
    {
        $xml->startElement('DrctDbtTxInf');

        $xml->startElement('PmtId');
        $xml->writeElement('EndToEndId', mb_substr($invoice->number, 0, 35));
        $xml->endElement();

        $xml->startElement('InstdAmt');
        $xml->writeAttribute('Ccy', 'EUR');
        $xml->text(Money::machine($invoice->gross_cents));
        $xml->endElement();

        $xml->startElement('DrctDbtTx');
        $xml->startElement('MndtRltdInf');
        $xml->writeElement('MndtId', mb_substr((string) $tenant->mandate_reference, 0, 35));
        $xml->writeElement('DtOfSgntr', CarbonImmutable::parse($tenant->mandate_signed_on)->toDateString());
        $xml->endElement();
        $xml->endElement();

        if (filled($tenant->bic)) {
            $xml->startElement('DbtrAgt');
            $xml->startElement('FinInstnId');
            $xml->writeElement('BIC', $this->plain($tenant->bic));
            $xml->endElement();
            $xml->endElement();
        }

        $xml->startElement('Dbtr');
        $xml->writeElement('Nm', mb_substr((string) ($tenant->account_holder ?: $tenant->name), 0, 70));
        $xml->endElement();

        $xml->startElement('DbtrAcct');
        $xml->startElement('Id');
        $xml->writeElement('IBAN', $this->plain((string) $tenant->iban));
        $xml->endElement();
        $xml->endElement();

        $xml->startElement('RmtInf');
        $xml->writeElement('Ustrd', mb_substr('Factuur ' . $invoice->number, 0, self::MAX_REMITTANCE));
        $xml->endElement();

        $xml->endElement();
    }

    /**
     * Is er voor deze klant nog nooit geïncasseerd? Er wordt naar andere
     * facturen gekeken en niet naar deze, zodat een bestand dat opnieuw wordt
     * gemaakt er hetzelfde uit komt.
     */
    private function neverCollected(Invoice $invoice): bool
    {
        return !Invoice::on('central')
            ->where('tenant_id', $invoice->tenant_id)
            ->whereNotNull('collected_at')
            ->where('id', '!=', $invoice->id)
            ->exists();
    }

    private function plain(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
    }
}

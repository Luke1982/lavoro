<?php

namespace App\Services;

use App\Models\Central\Invoice;
use App\Models\Central\IssuerSetting;
use App\Models\Tenant;
use App\Support\Money;

/**
 * UBL 2.1 in het Nederlandse profiel (NLCIUS), het formaat dat de overheid en
 * de meeste boekhoudpakketten accepteren.
 *
 * Bedragen gaan als euro's met twee decimalen naar buiten; intern zijn het
 * centen. Dat omrekenen gebeurt op één plek zodat er geen halve centen kunnen
 * ontstaan tussen de regels en het totaal.
 */
class InvoiceUbl
{
    public function __construct(private Invoice $invoice, private Tenant $tenant) {}

    public function toXml(): string
    {
        $issuer = IssuerSetting::all_values();

        $x = new \XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');

        $x->startElement('Invoice');
        $x->writeAttribute('xmlns', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $x->writeAttribute('xmlns:cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $x->writeAttribute('xmlns:cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        $x->writeElement('cbc:CustomizationID', 'urn:cen.eu:en16931:2017#compliant#urn:fdc:nen.nl:nlcius:v1.0');
        $x->writeElement('cbc:ProfileID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
        $x->writeElement('cbc:ID', $this->invoice->number);
        $x->writeElement('cbc:IssueDate', $this->invoice->issued_on->format('Y-m-d'));

        if ($this->invoice->due_on) {
            $x->writeElement('cbc:DueDate', $this->invoice->due_on->format('Y-m-d'));
        }

        $x->writeElement('cbc:InvoiceTypeCode', '380');
        $x->writeElement('cbc:DocumentCurrencyCode', 'EUR');

        $x->startElement('cac:InvoicePeriod');
        $x->writeElement('cbc:StartDate', $this->invoice->period_start->format('Y-m-d'));
        $x->writeElement('cbc:EndDate', $this->invoice->period_end->format('Y-m-d'));
        $x->endElement();

        $this->party($x, 'cac:AccountingSupplierParty', [
            'name' => $issuer['name'] ?? '',
            'address' => $issuer['address'] ?? '',
            'postcode' => $issuer['postcode'] ?? '',
            'city' => $issuer['city'] ?? '',
            'country' => $issuer['country'] ?? 'NL',
            'vat' => $issuer['vat_number'] ?? '',
            'coc' => $issuer['coc_number'] ?? '',
        ]);

        $this->party($x, 'cac:AccountingCustomerParty', [
            'name' => $this->tenant->name,
            'address' => (string) $this->tenant->invoice_address,
            'postcode' => (string) $this->tenant->invoice_postcode,
            'city' => (string) $this->tenant->invoice_city,
            'country' => $this->tenant->invoice_country ?: 'NL',
            'vat' => (string) $this->tenant->vat_number,
            'coc' => (string) $this->tenant->coc_number,
        ]);

        if (!empty($issuer['iban'])) {
            $x->startElement('cac:PaymentMeans');
            $x->writeElement('cbc:PaymentMeansCode', '58');
            $x->writeElement('cbc:PaymentID', $this->invoice->number);
            $x->startElement('cac:PayeeFinancialAccount');
            $x->writeElement('cbc:ID', $issuer['iban']);
            $x->endElement();
            $x->endElement();
        }

        $x->startElement('cac:TaxTotal');
        $x->startElement('cbc:TaxAmount');
        $x->writeAttribute('currencyID', 'EUR');
        $x->text(Money::machine($this->invoice->vat_cents));
        $x->endElement();
        $x->startElement('cac:TaxSubtotal');
        $this->amount($x, 'cbc:TaxableAmount', Money::machine($this->invoice->total_cents));
        $this->amount($x, 'cbc:TaxAmount', Money::machine($this->invoice->vat_cents));
        $x->startElement('cac:TaxCategory');
        $x->writeElement('cbc:ID', 'S');
        $x->writeElement('cbc:Percent', number_format($this->invoice->vat_percent, 2, '.', ''));
        $x->startElement('cac:TaxScheme');
        $x->writeElement('cbc:ID', 'VAT');
        $x->endElement();
        $x->endElement();
        $x->endElement();
        $x->endElement();

        $x->startElement('cac:LegalMonetaryTotal');
        $this->amount($x, 'cbc:LineExtensionAmount', Money::machine($this->invoice->subtotal_cents));
        $this->amount($x, 'cbc:TaxExclusiveAmount', Money::machine($this->invoice->total_cents));
        $this->amount($x, 'cbc:TaxInclusiveAmount', Money::machine($this->invoice->gross_cents));

        if ($this->invoice->discount_cents) {
            $this->amount($x, 'cbc:AllowanceTotalAmount', Money::machine($this->invoice->discount_cents));
        }

        $this->amount($x, 'cbc:PayableAmount', Money::machine($this->invoice->gross_cents));
        $x->endElement();

        foreach ($this->invoice->lines as $index => $line) {
            $x->startElement('cac:InvoiceLine');
            $x->writeElement('cbc:ID', (string) ($index + 1));
            $x->startElement('cbc:InvoicedQuantity');
            $x->writeAttribute('unitCode', 'C62');
            $x->text('1');
            $x->endElement();
            $this->amount($x, 'cbc:LineExtensionAmount', Money::machine($line->amount_cents));
            $x->startElement('cac:Item');
            $x->writeElement('cbc:Name', $line->description);
            $x->startElement('cac:ClassifiedTaxCategory');
            $x->writeElement('cbc:ID', 'S');
            $x->writeElement('cbc:Percent', number_format($this->invoice->vat_percent, 2, '.', ''));
            $x->startElement('cac:TaxScheme');
            $x->writeElement('cbc:ID', 'VAT');
            $x->endElement();
            $x->endElement();
            $x->endElement();
            $x->startElement('cac:Price');
            $this->amount($x, 'cbc:PriceAmount', Money::machine($line->amount_cents));
            $x->endElement();
            $x->endElement();
        }

        $x->endElement();
        $x->endDocument();

        return $x->outputMemory();
    }

    private function amount(\XMLWriter $x, string $tag, string $value): void
    {
        $x->startElement($tag);
        $x->writeAttribute('currencyID', 'EUR');
        $x->text($value);
        $x->endElement();
    }

    private function party(\XMLWriter $x, string $wrapper, array $p): void
    {
        $x->startElement($wrapper);
        $x->startElement('cac:Party');

        $x->startElement('cac:PartyName');
        $x->writeElement('cbc:Name', $p['name']);
        $x->endElement();

        $x->startElement('cac:PostalAddress');
        $x->writeElement('cbc:StreetName', $p['address']);
        $x->writeElement('cbc:CityName', $p['city']);
        $x->writeElement('cbc:PostalZone', $p['postcode']);
        $x->startElement('cac:Country');
        $x->writeElement('cbc:IdentificationCode', $p['country']);
        $x->endElement();
        $x->endElement();

        if ($p['vat'] !== '') {
            $x->startElement('cac:PartyTaxScheme');
            $x->writeElement('cbc:CompanyID', $p['vat']);
            $x->startElement('cac:TaxScheme');
            $x->writeElement('cbc:ID', 'VAT');
            $x->endElement();
            $x->endElement();
        }

        $x->startElement('cac:PartyLegalEntity');
        $x->writeElement('cbc:RegistrationName', $p['name']);

        if ($p['coc'] !== '') {
            $x->startElement('cbc:CompanyID');
            $x->writeAttribute('schemeID', '0106');
            $x->text($p['coc']);
            $x->endElement();
        }

        $x->endElement();
        $x->endElement();
        $x->endElement();
    }
}

<x-mail::message>
# Factuur {{ $invoice->number }}

Beste {{ $tenant->name }},

Bijgevoegd vind je de factuur voor de periode
{{ $invoice->period_start->format('d-m-Y') }} t/m {{ $invoice->period_end->format('d-m-Y') }}.

**Te betalen:** &euro; {{ number_format($invoice->gross_cents / 100, 2, ',', '.') }}
@if($invoice->due_on)
**Vervaldatum:** {{ $invoice->due_on->format('d-m-Y') }}
@endif

@if(!empty($issuer['iban']))
Graag overmaken op IBAN {{ $issuer['iban'] }} onder vermelding van {{ $invoice->number }}.
@endif

De factuur zit als PDF in de bijlage, met daarnaast een UBL-bestand dat je
boekhoudpakket rechtstreeks kan inlezen.

Met vriendelijke groet,<br>
{{ $issuer['name'] ?? 'MajorLabel' }}
</x-mail::message>

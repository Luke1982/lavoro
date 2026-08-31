<!doctype html>
<html lang="nl"><head><meta charset="utf-8">
<style>
    body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#111;margin:0}
    .wrap{padding:36px 44px}
    h1{font-size:20px;margin:0 0 2px}
    .muted{color:#666}
    .top{width:100%;margin-bottom:28px}
    .top td{vertical-align:top}
    table.lines{width:100%;border-collapse:collapse;margin-top:18px}
    table.lines th{text-align:left;border-bottom:1px solid #999;padding:6px 4px;font-size:10px;text-transform:uppercase;color:#666}
    table.lines td{padding:6px 4px;border-bottom:1px solid #eee}
    .r{text-align:right}
    .totals{width:46%;margin-left:54%;margin-top:14px;border-collapse:collapse}
    .totals td{padding:4px}
    .totals .grand td{border-top:1px solid #999;font-weight:bold;font-size:13px}
    footer{margin-top:34px;font-size:10px;color:#666;border-top:1px solid #eee;padding-top:10px}
</style></head>
<body><div class="wrap">

<table class="top"><tr>
    <td>
        <h1>Factuur</h1>
        <div class="muted">{{ $invoice->number }}</div>
    </td>
    <td class="r">
        <strong>{{ $issuer['name'] ?? '' }}</strong><br>
        {{ $issuer['address'] ?? '' }}<br>
        {{ $issuer['postcode'] ?? '' }} {{ $issuer['city'] ?? '' }}<br>
        @if(!empty($issuer['vat_number'])) BTW {{ $issuer['vat_number'] }}<br>@endif
        @if(!empty($issuer['coc_number'])) KvK {{ $issuer['coc_number'] }}@endif
    </td>
</tr></table>

<table class="top"><tr>
    <td>
        <div class="muted">Factuur aan</div>
        <strong>{{ $tenant->name }}</strong><br>
        {{ $tenant->invoice_address }}<br>
        {{ $tenant->invoice_postcode }} {{ $tenant->invoice_city }}<br>
        @if($tenant->vat_number) BTW {{ $tenant->vat_number }} @endif
    </td>
    <td class="r">
        Factuurdatum: {{ $invoice->issued_on->format('d-m-Y') }}<br>
        @if($invoice->due_on) Vervaldatum: {{ $invoice->due_on->format('d-m-Y') }}<br>@endif
        Periode: {{ $invoice->period_start->format('d-m-Y') }} t/m {{ $invoice->period_end->format('d-m-Y') }}
    </td>
</tr></table>

<table class="lines">
    <tr><th>Omschrijving</th><th class="r" style="width:110px">Bedrag</th></tr>
    @foreach($invoice->lines as $line)
        <tr><td>{{ $line->description }}</td><td class="r">&euro; {{ number_format($line->amount_cents / 100, 2, ',', '.') }}</td></tr>
    @endforeach
</table>

<table class="totals">
    @if($invoice->discount_cents)
        <tr><td>Subtotaal</td><td class="r">&euro; {{ number_format($invoice->subtotal_cents / 100, 2, ',', '.') }}</td></tr>
        <tr><td>Jaarkorting</td><td class="r">&minus; &euro; {{ number_format($invoice->discount_cents / 100, 2, ',', '.') }}</td></tr>
    @endif
    <tr><td>Netto</td><td class="r">&euro; {{ number_format($invoice->total_cents / 100, 2, ',', '.') }}</td></tr>
    <tr><td>BTW {{ $invoice->vat_percent }}%</td><td class="r">&euro; {{ number_format($invoice->vat_cents / 100, 2, ',', '.') }}</td></tr>
    <tr class="grand"><td>Te betalen</td><td class="r">&euro; {{ number_format($invoice->gross_cents / 100, 2, ',', '.') }}</td></tr>
</table>

<footer>
    @if(!empty($issuer['iban'])) Betaling op IBAN {{ $issuer['iban'] }} onder vermelding van {{ $invoice->number }}. @endif
    @if(!empty($issuer['email'])) Vragen? {{ $issuer['email'] }} @endif
</footer>

</div></body></html>

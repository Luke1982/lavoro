<!doctype html>
<html lang="nl"><head><meta charset="utf-8">
<style>
    @page { margin: 0 }

    @font-face {
        font-family: 'Dancing Script';
        src: url('{{ $script_font }}') format('truetype');
        font-weight: normal;
        font-style: normal;
    }

    body{font-family:DejaVu Sans,sans-serif;font-size:10.5px;color:#0f172a;margin:0;background:#fff}
    .wrap{padding:40px 46px}
    .muted{color:#64748b}
    .r{text-align:right}

    /* kop */
    .head{width:100%;margin-bottom:30px}
    .head td{vertical-align:top}
    .title-bar{border-left:5px solid #2563eb;padding-left:16px}
    h1{font-size:42px;line-height:1;margin:0 0 12px;letter-spacing:-1.5px}
    .number{font-size:12px;font-weight:bold;color:#2563eb;margin:0}
    .logo{height:77px}
    .issuer{border-collapse:collapse}
    .issuer td{padding:0;text-align:left}
    .contact{margin-top:16px;border-collapse:collapse}
    .contact td{padding:2px 0 6px 0;vertical-align:top;font-size:10px;line-height:1.45}
    .contact td.ico{width:11pt;padding:6px 0 0 0}
    .contact td.txt{padding-left:10px}

    /* kaarten */
    .cards{width:100%;border-collapse:separate;border-spacing:16px 0;margin:0 -16px 24px}
    .cards td{vertical-align:top;width:50%}
    .card{background:#f1f5f9;border-radius:12px;padding:16px 18px}
    .card .label{font-size:10px;font-weight:bold;color:#2563eb;margin:0 0 7px}
    .card .name{font-size:13.5px;font-weight:bold;margin:0 0 4px}
    .card td{vertical-align:middle}
    .bubble-cell{width:36pt;padding-right:16px}

    .meta{border-collapse:collapse}
    .meta td{padding:3px 0;font-size:10.5px;white-space:nowrap}
    .meta td.k{color:#475569;width:90px}

    /* regels */
    .lines{width:100%;border-collapse:collapse;border-radius:12px;margin-bottom:4px}
    .lines th{background:#0f172a;color:#fff;text-align:left;padding:13px 18px;
        font-size:9.5px;letter-spacing:.1em}
    .lines th:first-child{border-top-left-radius:12px}
    .lines th:last-child{border-top-right-radius:12px}
    .lines td{padding:12px 18px;border-bottom:1px solid #e2e8f0;background:#f8fafc;vertical-align:middle}
    .lines tr:last-child td{border-bottom:0}
    .lines td.ico{width:22pt;padding-right:0}
    .lines td.txt{padding-left:14px}

    /* totalen */
    .totals{margin-left:48%;width:52%;border-collapse:collapse}
    .totals td{padding:6px 18px;font-size:11px}
    .totals .pay td{background:#dbeafe;font-size:16px;font-weight:bold;padding:15px 18px}
    .totals .pay td:first-child{border-top-left-radius:10px;border-bottom-left-radius:10px}
    .totals .pay td:last-child{color:#2563eb;border-top-right-radius:10px;border-bottom-right-radius:10px}

    /* betaling */
    .pay-card{margin-top:28px;background:#f1f5f9;border-left:5px solid #2563eb;border-radius:12px;padding:18px 22px}
    .pay-card table{width:100%;border-collapse:collapse}
    .pay-card td{vertical-align:middle;line-height:1.5}
    .thanks{font-family:'Dancing Script',DejaVu Serif,serif;font-size:26px;color:#2563eb;margin:0 0 6px;line-height:1}

    footer{margin-top:24px;background:#f8fafc;border-radius:12px;padding:11px 22px;font-size:10px;color:#475569}
    footer table{width:100%;border-collapse:collapse}
    footer td{vertical-align:middle;padding:0}
    .foot-item{border-collapse:collapse}
    .foot-item td{vertical-align:middle;padding:0;text-align:left}
    .foot-item td.ico{width:11pt}
    .foot-item td.txt{padding-left:9px}
</style></head>
<body><div class="wrap">

<table class="head"><tr>
    <td>
        <div class="title-bar">
            <h1>Factuur</h1>
            <p class="number">{{ $invoice->number }}</p>
        </div>
    </td>
    <td class="r" style="width:46%">
        <table align="right" class="issuer"><tr><td>
            @if($logo)
                <img class="logo" src="{{ $logo }}" alt="{{ $issuer['name'] ?? '' }}">
            @else
                <strong style="font-size:17px">{{ $issuer['name'] ?? '' }}</strong>
            @endif
        </td></tr><tr><td>

        <table class="contact">
            <tr>
                <td class="ico">@include('landlord.invoice.icon', ['name' => 'pin', 'size' => 11])</td>
                <td class="txt">{{ $issuer['address'] ?? '' }}<br>{{ $issuer['postcode'] ?? '' }} {{ $issuer['city'] ?? '' }}</td>
            </tr>
            @if(!empty($issuer['vat_number']) || !empty($issuer['coc_number']))
                <tr>
                    <td class="ico">@include('landlord.invoice.icon', ['name' => 'file', 'size' => 11])</td>
                    <td class="txt">
                        @if(!empty($issuer['vat_number'])) BTW {{ $issuer['vat_number'] }}<br>@endif
                        @if(!empty($issuer['coc_number'])) KvK {{ $issuer['coc_number'] }}@endif
                    </td>
                </tr>
            @endif
            @if(!empty($issuer['phone']))
                <tr>
                    <td class="ico">@include('landlord.invoice.icon', ['name' => 'phone', 'size' => 11])</td>
                    <td class="txt">{{ $issuer['phone'] }}</td>
                </tr>
            @endif
            @if(!empty($issuer['email']))
                <tr>
                    <td class="ico">@include('landlord.invoice.icon', ['name' => 'mail', 'size' => 11])</td>
                    <td class="txt">{{ $issuer['email'] }}</td>
                </tr>
            @endif
        </table>

        </td></tr></table>
    </td>
</tr></table>

<table class="cards"><tr>
    <td>
        <div class="card">
            <p class="label">Factuur aan</p>
            <p class="name">{{ $tenant->name }}</p>
            {{ $tenant->invoice_address }}<br>
            {{ $tenant->invoice_postcode }} {{ $tenant->invoice_city }}
            @if($tenant->vat_number)<br>BTW {{ $tenant->vat_number }}@endif
        </div>
    </td>
    <td>
        <div class="card">
            <table><tr>
                <td class="bubble-cell">@include('landlord.invoice.icon', ['name' => 'calendar-bubble', 'size' => 36])</td>
                <td>
                    <table class="meta">
                        <tr><td class="k">Factuurdatum</td><td>{{ $invoice->issued_on->format('d-m-Y') }}</td></tr>
                        @if($invoice->due_on)
                            <tr><td class="k">Vervaldatum</td><td>{{ $invoice->due_on->format('d-m-Y') }}</td></tr>
                        @endif
                        <tr><td class="k">Periode</td><td>{{ $invoice->period_start->format('d-m-Y') }} t/m {{ $invoice->period_end->format('d-m-Y') }}</td></tr>
                    </table>
                </td>
            </tr></table>
        </div>
    </td>
</tr></table>

@php
    $line_icons = [
        'subscription' => 'calendar',
        'seats' => 'users',
        'module' => 'box',
        'storage' => 'drive',
        'discount' => 'tag',
        'topup' => 'coin',
        'proration' => 'refresh',
    ];
@endphp

<table class="lines">
    <tr><th colspan="2">OMSCHRIJVING</th><th class="r" style="width:130px">BEDRAG</th></tr>
    @foreach($invoice->lines as $line)
        <tr>
            <td class="ico">@include('landlord.invoice.icon', [
                'name' => ($line_icons[$line->kind] ?? 'file') . '-dot', 'size' => 22,
            ])</td>
            <td class="txt">{{ $line->description }}</td>
            <td class="r">{!! $line->amount_cents < 0 ? '&minus;' : '' !!}&euro; {{ number_format(abs($line->amount_cents) / 100, 2, ',', '.') }}</td>
        </tr>
    @endforeach
</table>

<table class="totals">
    @if($invoice->discount_cents)
        <tr><td>Subtotaal</td><td class="r">&euro; {{ number_format($invoice->subtotal_cents / 100, 2, ',', '.') }}</td></tr>
        <tr><td>Jaarkorting</td><td class="r">&minus;&euro; {{ number_format($invoice->discount_cents / 100, 2, ',', '.') }}</td></tr>
    @endif
    <tr><td>Netto</td><td class="r">&euro; {{ number_format($invoice->total_cents / 100, 2, ',', '.') }}</td></tr>
    <tr><td>BTW {{ $invoice->vat_percent }}%</td><td class="r">&euro; {{ number_format($invoice->vat_cents / 100, 2, ',', '.') }}</td></tr>
    <tr class="pay"><td>Te betalen</td><td class="r">&euro; {{ number_format($invoice->gross_cents / 100, 2, ',', '.') }}</td></tr>
</table>

<div class="pay-card">
    <table><tr>
        <td style="width:39pt">
            @include('landlord.invoice.icon', ['name' => 'card-bubble', 'size' => 39])
        </td>
        <td>
            <strong>Betaling</strong><br>
            @if(!empty($issuer['iban']))
                Op IBAN {{ $issuer['iban'] }}<br>onder vermelding van {{ $invoice->number }}.
            @else
                Betaalgegevens volgen apart, onder vermelding van {{ $invoice->number }}.
            @endif
        </td>
        <td class="r" style="width:42%">
            <p class="thanks">Bedankt!</p>
            <span class="muted">Wij waarderen het vertrouwen<br>en de prettige samenwerking.</span>
        </td>
    </tr></table>
</div>

<footer>
    @php
        $footer_items = array_values(array_filter([
            ['globe', $issuer['website'] ?? 'majorlabel.nl'],
            ['phone', $issuer['phone'] ?? null],
            ['mail', $issuer['email'] ?? null],
        ], fn ($item) => filled($item[1])));
    @endphp

    <table><tr>
        @foreach($footer_items as $index => $item)
            {{-- Eerste links, laatste rechts, de rest ertussenin: zo staan de
                 groepjes over de hele breedte verdeeld in plaats van op een
                 kluitje links. --}}
            <td style="width:{{ round(100 / max(1, count($footer_items)), 4) }}%;text-align:{{ $index === 0 ? 'left' : ($index === count($footer_items) - 1 ? 'right' : 'center') }}">
                <table class="foot-item" align="{{ $index === 0 ? 'left' : ($index === count($footer_items) - 1 ? 'right' : 'center') }}"><tr>
                    <td class="ico">@include('landlord.invoice.icon', ['name' => $item[0] . '-grey', 'size' => 11])</td>
                    <td class="txt">{{ $item[1] }}</td>
                </tr></table>
            </td>
        @endforeach
    </tr></table>
</footer>

</div></body></html>

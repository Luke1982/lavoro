<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <title>Keuring {{ $serviceJob->id }}</title>
    <style>
        @page {
            margin: 12mm 14mm;
        }

        body,
        table,
        td,
        th,
        p,
        span,
        div,
        strong,
        b {
            font-family: Helvetica, Arial, DejaVu Sans, sans-serif !important;
            font-size: 11px;
            line-height: 1.35;
        }

        .fw600 {
            font-weight: 600;
        }

        body {
            color: #111;
        }

        h1 {
            text-align: center;
            font-size: 16px;
            margin: 0 0 10px;
            text-transform: uppercase;
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
        }

        h2 {
            font-size: 13px;
            margin: 18px 0 6px;
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #b9c2cc;
            padding: 4px 6px;
            vertical-align: top;
        }

        th {
            background: #eef2f6;
            font-size: 10px;
            text-align: left;
        }

        .section-head {
            background: #d6dce2;
            font-weight: 700;
        }

        .muted {
            color: #555;
        }

        .small {
            font-size: 10px;
        }

        .nowrap {
            white-space: nowrap;
        }

        .foot {
            margin-top: 18px;
            font-size: 9px;
            color: #555;
            text-align: center;
        }

        .result-col {
            width: 30%;
        }

        .remark-col {
            width: 35%;
        }

        .footer {
            position: fixed;
            left: 14mm;
            right: 14mm;
            bottom: -4mm;
            font-size: 9px;
            color: #555;
            text-align: center;
        }
    </style>
</head>

<body>
    <table style="width:100%; border-collapse:collapse; margin-bottom:6px;">
        <tr>
            <td style="width:35%; vertical-align:middle;">
                @if ($logo['data'] ?? null)
                    <img src="{{ $logo['data'] }}" alt="Logo" style="{{ $logo['style'] }}" />
                @endif
            </td>
            <td style="text-align:center; vertical-align:middle;">
                <h1 style="margin:0;">Checklist periodieke inspectie / keuring {{ $ptName }}</h1>
            </td>
        </tr>
    </table>

    <table class="small" style="margin-bottom:12px;">
        <tr>
            <th style="width:20%">Naam klant / eigenaar</th>
            <td style="width:30%">{{ $customer?->name }}</td>
            <th style="width:20%">Tel.nr.</th>
            <td style="width:30%">{{ $customer?->phone ?? '—' }}</td>
        </tr>
        <tr>
            <th style="width:20%">Adres</th>
            <td style="width:30%">
                {{ trim(($customer?->address ?? '') . ' ' . ($customer?->postal_code ?? '') . ' ' . ($customer?->city ?? '')) }}
            </td>
            <th style="width:20%">Datum keuring</th>
            <td style="width:30%">{{ optional($serviceJob->created_at)->format('d-m-Y') }}</td>
        </tr>
        <tr>
            <th style="width:20%">Naam keurmeester</th>
            <td style="width:30%">{{ $serviceJob->completedBy->name ?? '—' }}</td>
            <th style="width:20%">Werkbon #</th>
            <td style="width:30%">{{ $serviceJob->service_order_id }}</td>
        </tr>
        <tr>
            <th style="width:20%">Merk</th>
            <td style="width:30%">{{ $product?->brand?->name ?? '—' }}</td>
            <th style="width:20%">Model / Serienummer</th>
            <td style="width:30%">{{ trim(($product?->model ?? '') . ' / ' . ($asset?->serial_number ?? '')) }}</td>
        </tr>
    </table>

    @foreach ($groups as $index => $group)
        <h2>{{ $loop->iteration . '. ' . $group['name'] }}</h2>
        <table class="small">
            <thead>
                <tr>
                    <th style="width:35%">Controlepunt</th>
                    <th class="result-col">Resultaat</th>
                    <th class="remark-col">Opmerking</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($group['items'] as $item)
                    @php
                        $type = $item['type'];
                        $descRaw = trim((string) ($item['description'] ?? ''));
                        $values = $item['values'];
                        $result = null;
                        if ($type === 'radio') {
                            if (count($values) > 0) {
                                $result = $values[0];
                            }
                        } elseif ($type === 'checkgroup') {
                            $result = implode(', ', $values);
                        } elseif ($type === 'boolean') {
                            $switchState = $item['switch_state'] ?? null;
                            if ($switchState === null) {
                                $result = null;
                            } elseif ((int) $switchState === 1) {
                                $result = 'Ja';
                            } else {
                                $result = 'Nee';
                            }
                        } elseif (in_array($type, ['number', 'text'])) {
                            $result = $descRaw;
                        } else {
                            $result = implode(', ', $values) ?: $descRaw;
                        }
                        $remark = null;
                        if ($type === 'radio') {
                            if ($descRaw !== '') {
                                $remark = $descRaw;
                            }
                        } elseif ($type === 'checkgroup' && $descRaw !== '') {
                            $remark = $descRaw;
                        } elseif ($type === 'boolean') {
                            if ($descRaw !== '') {
                                $remark = $descRaw;
                            }
                        }
                        $attached = $item['remarks'];
                        if (count($attached) > 0) {
                            $remark =
                                '<ul style="margin:0;padding-left:14px;">' .
                                collect($attached)->map(fn($r) => '<li>' . nl2br(e($r)) . '</li>')->implode('') .
                                '</ul>';
                        }
                        $result = $result ?: '—';
                        if ($result === 'Nee' && $type !== 'boolean') {
                            $result = null;
                        }
                        if ($remark === null || $remark === '') {
                            $remark = '—';
                        }
                    @endphp
                    <tr>
                        <td>{{ $item['check_name'] }}</td>
                        <td>{{ $result }}</td>
                        <td>{!! $remark !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    {{-- remarks/outcome data supplied by controller --}}
    <h2>{{ count($groups) + 2 . '. ' }}Resultaat & Verklaring</h2>
    <table class="small" style="margin-bottom:14px;">
        <tr>
            <th colspan="3" style="text-align:left;">Opmerkingen:</th>
        </tr>
        <tr>
            <td colspan="3" style="height:75px; vertical-align:top; padding:4px;">
                {!! $remarksText !== '' ? nl2br(e($remarksText)) : '&nbsp;' !!}
            </td>
        </tr>
        @if ($isTempApproved)
            <tr>
                <td colspan="3" style="font-weight:600;">Deze {{ $ptNameLower }} is tijdelijk goedgekeurd voor een
                    periode van {{ $tmpDays }} dagen en moet opnieuw gekeurd worden vóór {{ $tmpUntil ?? '-' }}.
                </td>
            </tr>
        @endif
        @if ($isTempApproved)
            <tr>
                <td>Noodzakelijke reparaties voordat de {{ $ptNameLower }} goedgekeurd kan worden:</td>
                <td colspan="2">&nbsp;</td>
            </tr>
        @endif
        @if ($isTempApproved)
            <tr>
                <td>Is er sprake van goedkeur na reparatie?</td>
                <td style="text-align:center;">{{ $isRepair ? 'JA' : '' }}</td>
                <td style="text-align:center;">{{ !$isRepair && $outcome ? 'NEE' : '' }}</td>
            </tr>
        @endif
    </table>

    <table class="small" style="margin-top:4px;">
        <tr>
            <td
                style="width:12%; border:1px solid #b9c2cc; text-align:center; vertical-align:middle; font-size:30px; line-height:1;">
                &#9888;</td>
            <td style="border:1px solid #b9c2cc; font-weight:600;">Indien de {{ $ptNameLower }} wordt gedemonteerd of
                is blootgesteld aan extreme hitte als gevolg van een brand (van korte duur) in de directe omgeving van
                de {{ $ptNameLower }}, dan dient de inspectie/keuring opnieuw te worden uitgevoerd!</td>
        </tr>
    </table>

    <div class="footer">{{ $company?->name }} {{ $company?->address_line1 }} @if ($company?->address_line2)
            {{ $company?->address_line2 }}
        @endif {{ $company?->postal_code }} {{ $company?->city }} {{ $company?->country }} |
        Gegenereerd op {{ now()->format('d-m-Y H:i') }} | Asset ID {{ $asset?->id }} | Keuring
        #{{ $serviceJob->id }}</div>
</body>

</html>

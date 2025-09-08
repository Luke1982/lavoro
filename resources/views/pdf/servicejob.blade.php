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
    </style>
</head>

<body>
    @php
        use Illuminate\Support\Str;
        $asset = $serviceJob->asset;
        $product = $asset?->product;
        $pt = $product?->productType;
        $customer = $asset?->customer;
        $ptName = trim((string) ($pt->name ?? 'installatie'));
        $ptNameLower = Str::lower($ptName);
    @endphp
    <h1>Checklist periodieke inspectie / keuring {{ $pt?->name }}</h1>

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
            <td style="width:30%">{{ $serviceJob->serviceOrder?->signed_by ?? '—' }}</td>
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
                @foreach ($group['items'] as $ci)
                    @php
                        $check = $ci->serviceCheck;
                        $values = $ci->values; // collection
                        $type = $check?->type;
                        $result = null;
                        if ($type === 'radio') {
                            // For radio checks: description may store a boolean-like numeric or text (1/0/ja/nee)
                            $rawDesc = trim((string) $ci->description);
                            $recognizedBoolean = false;
                            if ($rawDesc === '' || $rawDesc === '0') {
                                // Explicit rule: empty or zero means Nee
                                $result = 'Nee';
                                $recognizedBoolean = true;
                            } elseif ($rawDesc !== '') {
                                $lower = Str::lower($rawDesc);
                                if (in_array($lower, ['1', 'ja', 'yes', 'true', 'ok', 'y'])) {
                                    $result = 'Ja';
                                    $recognizedBoolean = true;
                                } elseif (in_array($lower, ['0', 'nee', 'no', 'false', 'nok', 'n'])) {
                                    $result = 'Nee';
                                    $recognizedBoolean = true;
                                }
                                if (!$recognizedBoolean) {
                                    // Not a recognizable boolean: treat description itself as result
                                    $result = $rawDesc;
                                }
                            }
                            // If still no result and values exist, fallback to first selected value
                            if (!$recognizedBoolean && !$result) {
                                $result = optional($values->first())->value;
                            }
                        } elseif ($type === 'checkgroup') {
                            $result = $values->pluck('value')->implode(', ');
                        } elseif ($type === 'boolean') {
                            // Boolean uses description (1/0/ja/nee) not values; empty or zero => Nee
                            $rawOriginal = (string) $ci->description;
                            $raw = Str::lower(trim($rawOriginal));
                            if ($raw === '' || $raw === '0') {
                                $result = 'Nee';
                            } elseif (in_array($raw, ['1', 'ja', 'yes', 'true', 'ok', 'y'])) {
                                $result = 'Ja';
                            } elseif (in_array($raw, ['nee', 'no', 'false', 'nok', 'n'])) {
                                $result = 'Nee';
                            } elseif ($raw !== '') {
                                $result = $rawOriginal; // some custom text
                            }
                        } elseif (in_array($type, ['number', 'text'])) {
                            $result = $ci->description;
                        } else {
                            // fallback: any selected values else description
                            $result = $values->pluck('value')->implode(', ') ?: $ci->description;
                        }
                        // Build remark separate from result. For radio where we recognized boolean, we suppress numeric/raw remark.
                        $remark = null;
                        if ($type === 'radio') {
                            $rawDesc = trim((string) $ci->description);
                            $lower = Str::lower($rawDesc);
                            $isBool = in_array($lower, [
                                '1',
                                '0',
                                'ja',
                                'nee',
                                'yes',
                                'no',
                                'true',
                                'false',
                                'ok',
                                'nok',
                                'y',
                                'n',
                            ]);
                            if ($rawDesc !== '' && !$isBool) {
                                $remark = $rawDesc; // only show if it's not the pure boolean token
    }
} elseif ($type === 'checkgroup' && $ci->description) {
    $remark = $ci->description;
} elseif ($type === 'boolean') {
    // For boolean, only show remark if description is NOT a pure boolean token
    $raw = Str::lower(trim((string) $ci->description));
    $isBool = in_array($raw, [
        '1',
        '0',
        'ja',
        'nee',
        'yes',
        'no',
        'true',
        'false',
        'ok',
        'nok',
        'y',
        'n',
    ]);
    if ($raw !== '' && !$isBool) {
        $remark = $ci->description;
    }
} elseif (in_array($type, ['number', 'text'])) {
    // number/text already stored as result
    $remark = '';
}
// If there are attached remarks, they take precedence and are shown as list
$instanceRemarks = $ci->remarks ?? collect();
if ($instanceRemarks->count() > 0) {
    $items = $instanceRemarks
        ->map(function ($r) {
            return '<li>' . nl2br(e($r->content)) . '</li>';
        })
        ->implode('');
    $remark = '<ul style="margin:0;padding-left:14px;">' . $items . '</ul>';
}
$result = $result ?: '—';
if ($remark === null || $remark === '') {
    $remark = '—';
                        }
                    @endphp
                    <tr>
                        <td>{{ $check?->name }}</td>
                        <td>{{ $result }}</td>
                        <td>{!! $remark !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    @php $remarksText = trim((string) $serviceJob->description); @endphp

    @php
        $outcome = $serviceJob->outcome; // string e.g. Goedkeur / Afkeur / etc.
        $tmpDays = $serviceJob->days_temporary_approval;
        $tmpUntil = null;
        if ($outcome === \App\Enums\ServiceJobOutcomes::tijdelijk_goedkeur->value && $tmpDays) {
            $tmpUntil = optional($serviceJob->created_at)->copy()->addDays($tmpDays)->format('d-m-Y');
        }
        $isApproved = $outcome === \App\Enums\ServiceJobOutcomes::goedkeur->value;
        $isTempApproved = $outcome === \App\Enums\ServiceJobOutcomes::tijdelijk_goedkeur->value;
        $isRejected = $outcome === \App\Enums\ServiceJobOutcomes::afkeur->value;
        $isRepair = $outcome === \App\Enums\ServiceJobOutcomes::reparatie->value;
    @endphp

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

    <div class="foot">Gegenereerd op {{ now()->format('d-m-Y H:i') }} | Asset ID {{ $asset?->id }} | Keuring
        #{{ $serviceJob->id }}</div>
</body>

</html>

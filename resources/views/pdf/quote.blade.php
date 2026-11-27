@php
    use App\Support\Money;
    use App\Support\Quantity;

    $quote = $document['quote'];
    $seller = $document['seller'] ?? [];
    $layout = $document['layout'];
    $totals = $document['totals'];
    $incl = $layout['incl_vat'];
    $lines = collect($document['lines']);
    $hasDiscount = $lines->flatMap(fn ($line) => [$line, ...$line['children'] ?? []])
        ->contains(fn ($line) => ($line['discount_percent'] ?? 0) > 0);
    $price = fn (array $line, float $amount) => Money::euros($incl ? $amount * (1 + $line['vat_percent'] / 100) : $amount);
    $columns = array_filter([
        'quantity' => $layout['quantity'] ? 'Aantal' : null,
        'unit_price' => $layout['unit_price'] ? 'Prijs' : null,
        'discount' => $layout['discount'] && $hasDiscount ? 'Korting' : null,
        'vat' => $layout['vat'] ? 'Btw' : null,
        'line_total' => $layout['line_total'] ? 'Totaal' : null,
    ]);
    $hasProvisional = $lines->contains(fn ($line) => $line['is_provisional'] ?? false);
    $choiceStarts = $lines->filter(fn ($line) => $line['choice_group'] ?? null)->unique('choice_group')->pluck('id')->all();
    $registration = collect([
        'KvK' => $seller['coc_number'] ?? null,
        'Btw' => $seller['vat_number'] ?? null,
        'IBAN' => $seller['iban'] ?? null,
    ])
        ->filter()
        ->map(fn ($value, $label) => $label . ' ' . $value)
        ->all();
    $cells = function (array $line) use ($columns, $price) {
        return collect(array_keys($columns))->map(fn ($column) => match ($column) {
            'quantity' => Quantity::human($line['quantity']) . ' ' . ($line['unit'] ?? ''),
            'unit_price' => $price($line, $line['unit_price']),
            'discount' => $line['discount_percent'] ? Quantity::human($line['discount_percent']) . '%' : '',
            'vat' => Quantity::human($line['vat_percent']) . '%',
            'line_total' => $price($line, $line['net']),
        })->all();
    };
@endphp
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <title>Offerte {{ $quote['number'] }}</title>
    <style>
        @page { margin: 16mm 18mm 24mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #333; font-size: 10.5px; line-height: 1.45; }
        h1 { font-size: 18px; margin: 18px 0 6px; color: #111; }
        h2 { font-size: 12.5px; margin: 18px 0 6px; color: #111; }
        p { margin: 0 0 6px; }
        .muted { color: #777; }
        .small { font-size: 9px; }
        .right { text-align: right; }
        .header td { vertical-align: top; }
        .seller { text-align: right; font-size: 9.5px; color: #555; }
        .addressee td { vertical-align: top; padding: 0; }
        .meta td { padding: 1px 0 1px 10px; font-size: 9.5px; }
        .meta td.label { color: #777; }
        .lines { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .lines th { text-align: left; font-size: 9px; color: #555; border-bottom: 1px solid #ccc; padding: 4px 5px; }
        .lines td { padding: 5px; border-bottom: 1px solid #eee; vertical-align: top; }
        .lines th.num, .lines td.num { text-align: right; white-space: nowrap; }
        .lines tr.child td { font-size: 9.5px; color: #555; padding-top: 3px; padding-bottom: 3px; }
        .lines tr.child td.description { padding-left: 18px; }
        .lines tr.left-out td { color: #999; }
        .lines tr.text td { border-bottom: none; padding-top: 10px; }
        .details { font-size: 9px; color: #666; }
        .photo { float: left; width: 18mm; margin: 0 8px 2px 0; border: 1px solid #eee; }
        .clear { clear: both; }
        .choice-heading { font-size: 8.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #6d28d9; padding-bottom: 0; }
        .badge { font-size: 8px; padding: 0 4px; border-radius: 3px; background: #e0f2fe; color: #0369a1; }
        .badge.choice { background: #ede9fe; color: #6d28d9; }
        .badge.provisional { background: #fef3c7; color: #92400e; }
        .totals { width: 46%; margin-left: 54%; border-collapse: collapse; margin-top: 8px; }
        .totals td { padding: 2px 5px; }
        .totals tr.grand td { font-weight: bold; border-top: 1px solid #ccc; padding-top: 4px; }
        .terms td { padding: 2px 8px 2px 0; vertical-align: top; }
        .terms td.label { width: 28%; color: #777; }
        .schedule { border-collapse: collapse; }
        .schedule td { padding: 1px 12px 1px 0; }
        .sign { margin-top: 18px; width: 100%; border-collapse: collapse; page-break-inside: avoid; }
        .keep-together { page-break-inside: avoid; }
        .sign td { width: 50%; vertical-align: top; padding-right: 12px; }
        .line-to-sign { border-bottom: 1px solid #999; height: 26px; margin-bottom: 4px; }
        .signature { max-width: 70mm; max-height: 30mm; }
        .rich-text ul, .rich-text ol { margin: 0 0 6px 16px; padding: 0; }
        .form-line { border-bottom: 1px dotted #999; padding: 8px 0 2px; }
        .footer { position: fixed; left: 0; right: 0; bottom: -16mm; font-size: 8.5px; color: #777; text-align: center; }
    </style>
</head>

<body>
    <div class="footer">
        {{ implode(' · ', array_filter([$seller['name'] ?? null, ...$registration])) }}
    </div>

    <table width="100%" class="header">
        <tr>
            <td>
                @if ($logo['data'] ?? null)
                    <img src="{{ $logo['data'] }}" alt="Logo" style="{{ $logo['style'] }}" />
                @else
                    <strong style="font-size: 15px;">{{ $seller['name'] ?? '' }}</strong>
                @endif
            </td>
            <td class="seller">
                <strong>{{ $seller['name'] ?? '' }}</strong><br>
                @foreach (array_filter([
                    $seller['address_line1'] ?? null,
                    $seller['address_line2'] ?? null,
                    trim(($seller['postal_code'] ?? '') . ' ' . ($seller['city'] ?? '')),
                    $seller['phone'] ?? null,
                    $seller['email'] ?? null,
                    $seller['website'] ?? null,
                ]) as $row)
                    {{ $row }}<br>
                @endforeach
            </td>
        </tr>
    </table>

    <table width="100%" class="addressee" style="margin-top: 14px;">
        <tr>
            <td>
                <strong>{{ $quote['customer'] }}</strong><br>
                @if ($quote['contact'])
                    t.a.v. {{ $quote['contact'] }}<br>
                @endif
                @foreach ($quote['address'] ?? [] as $row)
                    {{ $row }}<br>
                @endforeach
            </td>
            <td class="right">
                <table class="meta" style="margin-left: auto;">
                    <tr><td class="label">Offerte</td><td>{{ $quote['number'] }}</td></tr>
                    <tr><td class="label">Versie</td><td>{{ $quote['version'] }}</td></tr>
                    <tr><td class="label">Datum</td><td>{{ \Illuminate\Support\Carbon::parse($quote['quote_date'])->format('d-m-Y') }}</td></tr>
                    @if ($quote['valid_until'])
                        <tr><td class="label">Geldig tot en met</td><td>{{ \Illuminate\Support\Carbon::parse($quote['valid_until'])->format('d-m-Y') }}</td></tr>
                    @endif
                    @if ($quote['location'] ?? null)
                        <tr><td class="label">Locatie</td><td>{{ $quote['location'] }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <h1>{{ $quote['title'] }}</h1>

    @if ($quote['intro_text'])
        <div class="rich-text">{!! $quote['intro_text'] !!}</div>
    @endif

    <table class="lines">
        <thead>
            <tr>
                <th>Omschrijving</th>
                @foreach ($columns as $label)
                    <th class="num">{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                @if ($line['kind'] === 'text')
                    <tr class="text">
                        <td colspan="{{ count($columns) + 1 }}">
                            <strong>{{ $line['description'] }}</strong>
                            @if ($line['details'])
                                <div class="rich-text">{!! $line['details'] !!}</div>
                            @endif
                        </td>
                    </tr>
                @else
                    @php
                        $leftOut = ($line['is_optional'] || ($line['choice_group'] ?? null)) && !$line['is_chosen'];
                    @endphp
                    @if (in_array($line['id'], $choiceStarts, true))
                        <tr class="text">
                            <td colspan="{{ count($columns) + 1 }}" class="choice-heading">Kies één van deze mogelijkheden</td>
                        </tr>
                    @endif
                    <tr class="{{ $leftOut ? 'left-out' : '' }}">
                        <td class="description">
                            @if ($photo = $images[$line['image_id'] ?? 0] ?? null)
                                <img src="{{ $photo }}" class="photo" alt="" />
                            @endif
                            {{ $line['description'] }}
                            @if ($line['choice_group'] ?? null)
                                <span class="badge choice">{{ $line['is_chosen'] ? 'alternatief, telt mee' : 'alternatief' }}</span>
                            @elseif ($line['is_optional'])
                                <span class="badge">{{ $line['is_chosen'] ? 'optie, telt mee' : 'optie' }}</span>
                            @endif
                            @if ($line['is_provisional'] ?? false)
                                <span class="badge provisional">stelpost</span>
                            @endif
                            @if ($line['location_note'] ?? null)
                                <div class="details">Ruimte: {{ $line['location_note'] }}</div>
                            @endif
                            @if ($line['details'])
                                <div class="details">{!! nl2br(e($line['details'])) !!}</div>
                            @endif
                            @if ($photo)
                                <div class="clear"></div>
                            @endif
                        </td>
                        @foreach ($cells($line) as $cell)
                            <td class="num">{{ $cell }}</td>
                        @endforeach
                    </tr>
                    @foreach ($line['children'] as $child)
                        <tr class="child {{ $leftOut ? 'left-out' : '' }}">
                            <td class="description">{{ $child['description'] }}</td>
                            @foreach ($cells($child) as $cell)
                                <td class="num">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                @endif
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Totaal excl. btw</td><td class="right">{{ Money::euros($totals['net']) }}</td></tr>
        @foreach ($totals['vat'] as $row)
            <tr class="muted"><td>Btw {{ Quantity::human($row['rate']) }}%</td><td class="right">{{ Money::euros($row['vat']) }}</td></tr>
        @endforeach
        <tr class="grand"><td>Totaal incl. btw</td><td class="right">{{ Money::euros($totals['gross']) }}</td></tr>
        @if ($totals['provisional_net'] ?? 0)
            <tr class="muted small"><td>Waarvan stelposten, excl. btw</td><td class="right">{{ Money::euros($totals['provisional_net']) }}</td></tr>
        @endif
        @if ($totals['optional_net'])
            <tr class="muted small"><td>Opties die niet meetellen, excl. btw</td><td class="right">{{ Money::euros($totals['optional_net']) }}</td></tr>
        @endif
    </table>

    @if ($quote['closing_text'])
        <div class="rich-text" style="margin-top: 14px;">{!! $quote['closing_text'] !!}</div>
    @endif

    <div class="keep-together">
    <h2>Afspraken</h2>
    <table class="terms" width="100%">
        @if ($quote['pricing_explanation'])
            <tr><td class="label">Prijs</td><td>{{ $quote['pricing_explanation'] }}</td></tr>
        @endif
        @if ($quote['start_period'] ?? null)
            <tr><td class="label">Start</td><td>{{ $quote['start_period'] }}</td></tr>
        @endif
        @if ($quote['duration'] ?? null)
            <tr><td class="label">Duur</td><td>{{ $quote['duration'] }}</td></tr>
        @endif
        @if ($quote['payment_terms'] ?? null)
            <tr><td class="label">Betaling</td><td>{{ $quote['payment_terms'] }}</td></tr>
        @endif
        @if ($quote['payment_schedule'])
            <tr>
                <td class="label">Betaaltermijnen</td>
                <td>
                    <table class="schedule">
                        @foreach ($quote['payment_schedule'] as $term)
                            <tr>
                                <td>{{ Quantity::human($term['percent']) }}%</td>
                                <td>{{ $term['description'] }}{{ $term['before_start'] ? ' (vóór de start)' : '' }}</td>
                                <td class="right">{{ Money::euros($term['amount']) }}</td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        @endif
        @if ($hasProvisional)
            <tr><td class="label">Stelposten</td><td>{{ \App\Domain\Quotes\QuoteForCustomer::PROVISIONAL_EXPLANATION }}</td></tr>
        @endif
        @if ($document['terms'])
            <tr>
                <td class="label">Voorwaarden</td>
                <td>
                    Op deze offerte zijn onze algemene voorwaarden van toepassing.
                    @if ($document['terms']['kind'] === 'link')
                        U leest ze op {{ $document['terms']['url'] }}.
                    @else
                        U ontvangt ze bij deze offerte ({{ $document['terms']['name'] }}).
                    @endif
                </td>
            </tr>
        @endif
        @if ($document['withdrawal'])
            <tr><td class="label">Bedenktijd</td><td>U hebt {{ $document['withdrawal']['days'] }} dagen bedenktijd nadat u akkoord geeft. Zie Herroepingsrecht hieronder.</td></tr>
        @endif
    </table>
    </div>

    @if ($signed)
        <h2>Akkoord</h2>
        <p>
            {{ $signed['name'] }} ging op {{ $signed['date'] }} {{ $signed['via'] }} akkoord met versie {{ $quote['version'] }} van deze offerte{{ $signed['terms_name'] ? ' en met de algemene voorwaarden (' . $signed['terms_name'] . ')' : '' }}.
        </p>
        @if ($signed['cooling_off_until'])
            <p>
                De bedenktijd loopt tot en met {{ $signed['cooling_off_until'] }}.
                {{ $signed['starts_within_cooling_off'] ? 'De klant heeft gevraagd de werkzaamheden binnen de bedenktijd te laten beginnen.' : 'De klant heeft niet gevraagd eerder te beginnen.' }}
            </p>
        @endif
        <img src="{{ $signed['signature'] }}" class="signature" alt="Handtekening" />
    @else
        <table class="sign">
            <tr>
                <td>
                    <div class="small muted">Voor akkoord, naam</div>
                    <div class="line-to-sign"></div>
                    <div class="small muted">Datum</div>
                    <div class="line-to-sign"></div>
                </td>
                <td>
                    <div class="small muted">Handtekening</div>
                    <div class="line-to-sign" style="height: 60px;"></div>
                </td>
            </tr>
        </table>
    @endif

    @if ($document['withdrawal'])
        <div class="keep-together" style="margin-top: 24px;">
        <h2>Herroepingsrecht</h2>
        @foreach ($document['withdrawal']['instructions'] as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach

        <h2>Modelformulier voor herroeping</h2>
        <p class="small muted">Dit formulier alleen invullen en terugzenden als u de overeenkomst wilt herroepen.</p>
        @foreach ($document['withdrawal']['form'] as $row)
            <div class="{{ str_ends_with($row, ':') ? 'form-line' : '' }}">{{ $row }}</div>
        @endforeach
        </div>
    @endif
</body>

</html>

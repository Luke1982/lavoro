<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <title>Werkbon {{ $serviceOrder->id }}</title>
    <style>
        @page {
            margin: 16mm 18mm 18mm 18mm;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            color: #333;
            font-size: 12px;
        }

        h1 {
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 4px;
            font-size: 20px;
            margin: 0 0 12px;
        }

        h2 {
            font-size: 14px;
            margin: 18px 0 6px;
        }

        .muted {
            color: #777;
        }

        .grid {
            width: 100%;
            border-collapse: collapse;
        }

        .grid td {
            padding: 4px 6px;
            vertical-align: top;
        }

        .grid .label {
            width: 25%;
            color: #555;
            font-size: 11px;
        }

        .hr {
            border-top: 1px solid #ddd;
            margin: 12px 0;
        }

        .section {
            margin-top: 8px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 6px;
            border-bottom: 1px solid #e5e7eb;
        }

        .table th {
            text-align: left;
            font-size: 11px;
            color: #444;
        }

        .table.compact th,
        .table.compact td {
            padding: 4px 6px;
        }

        .small {
            font-size: 11px;
        }

        .footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 0 18mm 4mm 18mm;
            font-size: 10px;
            color: #666;
            text-align: center;
        }

        .sign {
            margin-top: 30mm;
            margin-bottom: 8mm;
        }

        .columns {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .columns td {
            width: 50%;
            vertical-align: top;
            padding-right: 12px;
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
                <h1 style="margin:0;">WERKBON <span class="muted">{{ $serviceOrder->id }}</span></h1>
            </td>
            <td style="width:35%; text-align:right; vertical-align:middle;" class="small">
                @if ($serviceOrder->serviceOrderStage)
                    <div><span class="muted">Status:</span> {{ $serviceOrder->serviceOrderStage->name }}</div>
                @endif
                @if ($serviceOrder->external_purchaseorder_no)
                    <div><span class="muted">Externe referentie:</span> {{ $serviceOrder->external_purchaseorder_no }}</div>
                @endif
            </td>
        </tr>
    </table>
    <div class="hr" style="margin-top:4px;"></div>

    <h2>Ordergegevens</h2>
    <table class="columns">
        <tr>
            <td>
                <table class="grid">
                    <tr>
                        <td class="label">Ordernummer:</td>
                        <td>{{ $serviceOrder->id }}</td>
                    </tr>
                    <tr>
                        <td class="label">Klant:</td>
                        <td>{{ $serviceOrder->customer->name }}</td>
                    </tr>
                    <tr>
                        <td class="label">Contactpersoon:</td>
                        <td>{{ $serviceOrder->customer->contact_person ?? '—' }}</td>
                    </tr>
                </table>
            </td>
            <td>
                <table class="grid">
                    <tr>
                        <td class="label">Adres:</td>
                        <td>{{ $serviceOrder->customer->address }}</td>
                    </tr>
                    <tr>
                        <td class="label">PC / Plaats:</td>
                        <td>{{ $serviceOrder->customer->postal_code }} {{ $serviceOrder->customer->city }}</td>
                    </tr>
                    <tr>
                        <td class="label">Datum:</td>
                        <td>{{ optional($plannedDate)->format('d-m-Y') }}</td>
                    </tr>
                    @if ($serviceOrder->actual_start_time)
                        <tr>
                            <td class="label">Starttijd:</td>
                            <td>{{ substr($serviceOrder->actual_start_time, 0, 5) }}</td>
                        </tr>
                    @endif
                    @if ($serviceOrder->actual_end_time)
                        <tr>
                            <td class="label">Eindtijd:</td>
                            <td>{{ substr($serviceOrder->actual_end_time, 0, 5) }}</td>
                        </tr>
                    @endif
                    @if ($executionLocation)
                        <tr>
                            <td class="label">Uitvoeringslocatie:</td>
                            <td>{{ $executionLocation }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @if (($executingUsers ?? collect())->isNotEmpty())
        <h2 class="section">Uitvoerders</h2>
        <table class="table small compact">
            <thead>
                <tr>
                    <th style="width:24%">Naam</th>
                    <th style="width:15%">Datum</th>
                    <th style="width:14%">Starttijd</th>
                    <th style="width:14%">Eindtijd</th>
                    <th style="width:14%">Pauze</th>
                    <th style="width:19%">Uren</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($executingUsers as $executor)
                    <tr>
                        <td>{{ $executor['name'] }}</td>
                        <td>{{ optional($executor['date'])->format('d-m-Y') ?? '—' }}</td>
                        @if ($executor['actual_start'] || $executor['actual_end'])
                            <td>{{ optional($executor['actual_start'])->format('H:i') ?? '—' }}</td>
                            <td>{{ optional($executor['actual_end'])->format('H:i') ?? '—' }}</td>
                        @else
                            <td colspan="2" class="muted">Nog geen tijden ingevuld</td>
                        @endif
                        <td>{{ $executor['breaktime'] }} min</td>
                        <td>{{ $executor['hours'] !== null ? number_format($executor['hours'], 2, ',', '.') . ' uur' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (($tickets ?? collect())->isNotEmpty())
        <h2 class="section">Storingen</h2>
        <table class="table small compact">
            <thead>
                <tr>
                    <th style="width:34%">Merk / Model</th>
                    <th style="width:18%">Serienummer</th>
                    <th>Onderwerp</th>
                    <th style="width:16%">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tickets as $ticket)
                    <tr>
                        <td>{{ trim((optional($ticket->asset->product->brand)->name ?? '') . ' ' . ($ticket->asset->product->model ?? '')) ?: '—' }}
                        </td>
                        <td>{{ $ticket->asset->serial_number ?? '—' }}</td>
                        <td>{{ $ticket->subject ?? '—' }}</td>
                        <td>{{ $ticket->status ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (($descriptionText ?? '') !== '')
        <div class="hr"></div>
        <h2>Uitgevoerde werkzaamheden</h2>
        <div class="section small">
            {!! nl2br(e($descriptionText)) !!}
        </div>
    @endif

    @if (($jobs ?? collect())->isNotEmpty())
        <h2 class="section">Keuringen</h2>
        <table class="table small compact">
            <thead>
                <tr>
                    <th style="width:6%">#</th>
                    <th style="width:34%">Merk / Model</th>
                    <th style="width:18%">Serienummer</th>
                    <th style="width:18%">Uitkomst</th>
                    <th style="width:24%">Afgerond op</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($jobs as $job)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ trim((optional($job->asset->product->brand)->name ?? '') . ' ' . ($job->asset->product->model ?? '')) ?: '—' }}
                        </td>
                        <td>{{ $job->asset->serial_number ?? '—' }}</td>
                        <td>
                            @php
                                $outcomeCase = collect(\App\Enums\ServiceJobOutcomes::cases())->firstWhere(
                                    'value',
                                    $job->outcome,
                                );
                            @endphp
                            {{ $outcomeCase?->value ?? '—' }}
                            @if (($outcomeCase?->name ?? null) === 'tijdelijk_goedkeur' && (int) $job->days_temporary_approval > 0)
                                ({{ (int) $job->days_temporary_approval }} dagen)
                            @endif
                        </td>
                        <td>
                            @if ($job->completed_on)
                                {{ $job->completed_on->format('d-m-Y') }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (($materialsList ?? collect())->isNotEmpty())
        <h2 class="section">Materialen</h2>
        <table class="table small">
            <thead>
                <tr>
                    <th style="width: 15%">Aantal</th>
                    <th>Omschrijving</th>
                    <th style="width: 15%">Prijs pst.</th>
                    <th style="width: 15%">Totaal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($materialsList as $material)
                    <tr>
                        <td>{{ $material['quantity'] }}{{ $material['unit'] ? ' ' . $material['unit'] : '' }}
                        </td>
                        <td>{{ $material['description'] }}</td>
                        @if (! $material['has_price'])
                            <td>n.n.b.</td>
                            <td>n.n.b.</td>
                        @else
                            <td>€ {{ number_format($material['price'], 2, ',', '.') }}</td>
                            <td>€ {{ number_format($material['price'] * $material['quantity'], 2, ',', '.') }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="small muted" style="margin-top:4px;">Indien niet anders vermeld zijn alle prijzen excl. BTW</div>
    @endif

    @if (($extraMaterialsList ?? collect())->isNotEmpty())
        <h2 class="section">Extra materialen</h2>
        <table class="table small">
            <thead>
                <tr>
                    <th style="width: 15%">Aantal</th>
                    <th>Omschrijving</th>
                    <th style="width: 15%">Prijs pst.</th>
                    <th style="width: 15%">Totaal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($extraMaterialsList as $material)
                    <tr>
                        <td>{{ $material['quantity'] }}{{ $material['unit'] ? ' ' . $material['unit'] : '' }}
                        </td>
                        <td>{{ $material['description'] }}</td>
                        @if (! $material['has_price'] || (float) $material['price'] === 0.0)
                            <td>n.n.b.</td>
                            <td>n.n.b.</td>
                        @else
                            <td>€ {{ number_format($material['price'], 2, ',', '.') }}</td>
                            <td>€ {{ number_format($material['price'] * $material['quantity'], 2, ',', '.') }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="small muted" style="margin-top:4px;">Indien niet anders vermeld zijn alle prijzen excl. BTW</div>
    @endif

    @if (($taskInstances ?? collect())->isNotEmpty())
        <h2 class="section">Taken</h2>
        <table class="table small compact">
            <thead>
                <tr>
                    <th style="width:25%">Taak</th>
                    <th>Omschrijving</th>
                    <th style="width:18%">Serienummers</th>
                    <th style="width:25%">Ondertekend door</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($taskInstances as $instance)
                    <tr>
                        <td>{{ $instance->title ?? ($instance->serviceOrderTask?->title ?? '—') }}</td>
                        <td>{{ $instance->effective_description ?: '—' }}</td>
                        <td>
                            @if ($instance->assets->isNotEmpty())
                                {{ $instance->assets->pluck('serial_number')->filter()->implode(', ') ?: '—' }}
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if ($instance->signed_by)
                                <div>{{ $instance->signed_by }}</div>
                                <div class="muted" style="font-size:10px;">{{ $instance->signed_at?->copy()->setTimezone(config('app.display_timezone'))->format('d-m-Y H:i') }}</div>
                                <img src="{{ $instance->signature_base64 }}" alt="Handtekening"
                                    style="max-height:60px; max-width:180px; display:block; margin-top:4px;">
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (($remarks ?? collect())->isNotEmpty())
        <h2 class="section">Opmerkingen</h2>
        <table class="table small compact">
            <thead>
                <tr>
                    <th style="width:20%">Datum</th>
                    <th style="width:20%">Door</th>
                    <th>Opmerking</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($remarks as $remark)
                    <tr>
                        <td>{{ $remark->created_at->copy()->setTimezone(config('app.display_timezone'))->format('d-m-Y H:i') }}</td>
                        <td>{{ $remark->user->name ?? '—' }}</td>
                        <td>{{ $remark->content }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (($images ?? collect())->isNotEmpty())
        <h2 class="section">Foto's</h2>
        <div class="section">
            <table style="width:100%; border-collapse:collapse;">
                @foreach ($images->chunk(2) as $row)
                    <tr>
                        @foreach ($row as $image)
                            <td style="width:50%; padding:4px; vertical-align:top;">
                                <img src="{{ $image['data'] }}" alt="{{ $image['name'] }}"
                                    style="width:{{ $image['landscape'] ? '100%' : '60%' }}; height:auto; display:block;">
                                @if ($image['name'])
                                    <div class="small muted" style="margin-top:2px;">{{ $image['name'] }}</div>
                                @endif
                            </td>
                        @endforeach
                        @if ($row->count() === 1)
                            <td style="width:50%;"></td>
                        @endif
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    <div class="sign small">
        <table class="columns">
            <tr>
                <td>
                    <table class="grid">
                        <tr>
                            <td class="label">Naam:</td>
                            <td>{{ $serviceOrder->signed_by ?? '—' }}</td>
                        </tr>
                        <tr>
                            <td class="label" style="white-space:nowrap;">Werkzaamheden gereed:</td>
                            <td>{{ $serviceOrder->work_completed ? 'Ja' : 'Nee' }}</td>
                        </tr>
                    </table>
                </td>
                <td>
                    <table class="grid">
                        <tr>
                            <td class="label">Handtekening:</td>
                            <td>
                                @if ($serviceOrder->signature_base64)
                                    <img src="{{ $serviceOrder->signature_base64 }}" alt="Handtekening"
                                        style="max-height:80px; max-width:280px; display:block;">
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    @if (($closingText ?? '') !== '')
        <div class="hr"></div>
        <div class="section small closing-text">{!! nl2br(e($closingText)) !!}</div>
    @endif

    <div class="footer">{{ $company?->name }} {{ $company?->address_line1 }} @if ($company?->address_line2)
            {{ $company?->address_line2 }}
        @endif {{ $company?->postal_code }} {{ $company?->city }} {{ $company?->country }} | Op
        al onze offertes, opdrachten en overeenkomsten zijn onze algemene voorwaarden van toepassing. De algemene
        voorwaarden worden u op verzoek toegezonden.</div>
</body>

</html>

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
                @if(($logo['data'] ?? null))
                    <img src="{{ $logo['data'] }}" alt="Logo" style="{{ $logo['style'] }}" />
                @endif
            </td>
            <td style="text-align:center; vertical-align:middle;">
                <h1 style="margin:0;">WERKBON <span class="muted">{{ $serviceOrder->id }}</span></h1>
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
                        <td>{{ optional($serviceOrder->created_at)->format('d-m-Y') }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

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
            @forelse(($tickets ?? []) as $ticket)
                <tr>
                    <td>{{ trim((optional($ticket->asset->product->brand)->name ?? '') . ' ' . ($ticket->asset->product->model ?? '')) ?: '—' }}
                    </td>
                    <td>{{ $ticket->asset->serial_number ?? '—' }}</td>
                    <td>{{ $ticket->subject ?? '—' }}</td>
                    <td>{{ $ticket->status ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="small">Geen tickets gekoppeld.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if (($descriptionText ?? '') !== '')
        <div class="hr"></div>
        <h2>Uitgevoerde werkzaamheden</h2>
        <div class="section small">
            {!! nl2br(e($descriptionText)) !!}
        </div>
    @endif

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
            @forelse(($jobs ?? []) as $job)
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
            @empty
                <tr>
                    <td colspan="5" class="small">Geen servicejobs gekoppeld.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2 class="section">Materialen</h2>
    <table class="table small">
        <thead>
            <tr>
                <th style="width: 10%">Aantal</th>
                <th>Omschrijving</th>
                <th style="width: 15%">Prijs pst.</th>
                <th style="width: 15%">Totaal</th>
            </tr>
        </thead>
        <tbody>
            @forelse(($materialsList ?? []) as $material)
                <tr>
                    <td>{{ $material->pivot->quantity }}</td>
                    <td>{{ $material->name }}</td>
                    <td>€ {{ number_format($material->price, 2, ',', '.') }}</td>
                    <td>€ {{ number_format($material->price * $material->pivot->quantity, 2, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="small">Geen materialen toegevoegd.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="sign small">
        <table class="columns">
            <tr>
                <td>
                    <table class="grid">
                        <tr>
                            <td class="label">Naam:</td>
                            <td>{{ $serviceOrder->signed_by ?? '—' }}</td>
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

    <div class="footer">{{ $company?->name }} {{ $company?->address_line1 }} @if($company?->address_line2) {{ $company?->address_line2 }} @endif {{ $company?->postal_code }} {{ $company?->city }} {{ $company?->country }} | Op al onze offertes, opdrachten en overeenkomsten zijn onze algemene voorwaarden van toepassing. De algemene voorwaarden worden u op verzoek toegezonden.</div>
</body>

</html>

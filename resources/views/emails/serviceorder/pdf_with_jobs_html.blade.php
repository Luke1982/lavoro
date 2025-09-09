{{-- HTML version for combined service order + jobs mail --}}
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Werkbon #{{ $serviceOrder->id }} + keuringen</title>
    <style>
        body { font-family: -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,'Noto Sans',sans-serif,'Apple Color Emoji','Segoe UI Emoji'; margin:0; padding:0; background:#f4f6f8; }
        .wrapper { width:100%; padding:24px 0; }
        .container { max-width:570px; margin:0 auto; background:#ffffff; border-radius:4px; box-shadow:0 1px 3px rgba(0,0,0,.05); padding:24px; }
        h1 { font-size:18px; margin:0 0 20px; color:#1a202c; }
        p { line-height:1.55; font-size:14px; color:#374151; margin:0 0 16px; }
        .meta strong { color:#2d3748; }
        .footer { text-align:center; font-size:12px; color:#a0aec0; margin-top:28px; padding:24px 0 0; }
        .logo { text-align:center; margin-bottom:24px; }
        .logo img { max-height:56px; }
        .label { font-weight:600; }
        .desc { white-space: pre-line; }
        ul { padding-left:18px; margin:0 0 16px; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="logo">
            @php($logo = config('app.mail_logo_url'))
            @if (!$logo)
                @php($publicStorageLogo = public_path('storage/logo.png'))
                @if (file_exists($publicStorageLogo))
                    @php($logo = asset('storage/logo.png'))
                @endif
            @endif
            @if ($logo)
                <img src="{{ $logo }}" alt="{{ config('app.name') }}">
            @else
                <span style="font-size:20px;font-weight:600;color:#2d3748;">{{ config('app.name') }}</span>
            @endif
        </div>
        <div class="container">
            <h1>Werkbon #{{ $serviceOrder->id }} + keuringen</h1>
            <p>Beste {{ $serviceOrder->customer->contact_person ?? $serviceOrder->customer->name }},</p>
            <p>In de bijlage vindt u de PDF van de uitgevoerde werkzaamheden (werkbon) <strong>én</strong> alle bijbehorende keuringen ({{ $serviceOrder->servicejobs->count() }} stuks) als afzonderlijke PDF-bestanden.</p>
            <p class="meta">
                <span class="label">Datum:</span> {{ optional($serviceOrder->created_at)->format('d-m-Y') }}<br>
                <span class="label">Klant:</span> {{ $serviceOrder->customer->name }}
            </p>
            @php($desc = trim((string) ($serviceOrder->description ?? '')))
            <p><span class="label">Omschrijving:</span><br>
                <span class="desc">{{ $desc !== '' ? $desc : 'Geen omschrijving opgegeven.' }}</span>
            </p>
            @if($serviceOrder->servicejobs->count())
                <p><span class="label">Overzicht keuringen:</span></p>
                <ul>
                    @foreach($serviceOrder->servicejobs as $job)
                        <li>Keuring #{{ $job->id }} – {{ $job->asset?->product?->product_type?->name }} {{ $job->asset?->product?->brand?->name }} {{ $job->asset?->product?->model }} ({{ $job->asset?->serial_number }}) – Uitkomst: {{ $job->outcome }}</li>
                    @endforeach
                </ul>
            @endif
            <p>Met vriendelijke groet,</p>
            <p>{{ config('app.name') }}</p>
        </div>
        <div class="footer">&copy; {{ date('Y') }} {{ config('app.name') }}. Alle rechten voorbehouden.</div>
    </div>
</body>
</html>

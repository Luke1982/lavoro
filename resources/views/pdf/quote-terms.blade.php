<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <title>Algemene voorwaarden</title>
    <style>
        @page { margin: 16mm 18mm 20mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #333; font-size: 10px; line-height: 1.5; }
        h1 { font-size: 17px; margin: 16px 0 10px; color: #111; }
        h2 { font-size: 12.5px; margin: 14px 0 4px; color: #111; }
        h3, h4 { font-size: 11px; margin: 12px 0 4px; color: #111; }
        p { margin: 0 0 6px; }
        ul, ol { margin: 0 0 6px 16px; padding: 0; }
        .footer { position: fixed; left: 0; right: 0; bottom: -12mm; font-size: 8.5px; color: #777; text-align: center; }
    </style>
</head>

<body>
    <div class="footer">{{ $seller['name'] ?? '' }}</div>

    @if ($logo['data'] ?? null)
        <img src="{{ $logo['data'] }}" alt="Logo" style="{{ $logo['style'] }}" />
    @endif

    <h1>Algemene voorwaarden{{ ($seller['name'] ?? null) ? ' van ' . $seller['name'] : '' }}</h1>

    {!! $html !!}
</body>

</html>

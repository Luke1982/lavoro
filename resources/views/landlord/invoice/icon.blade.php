@php
    /**
     * Drawn up front and not handed over as an SVG. dompdf scales an SVG with a
     * ratio that turns out differently per image; a PNG with a fixed size comes
     * out as it goes in. The files are made with scripts/invoice-icons.py.
     */
    $file = public_path('img/invoice/' . $name . '.png');
    $data = is_readable($file)
        ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($file))
        : null;
@endphp
@if($data)
    <img src="{{ $data }}" width="{{ $size }}" height="{{ $size }}"
        style="width:{{ $size }}pt;height:{{ $size }}pt">
@endif

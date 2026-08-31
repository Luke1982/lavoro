@php
    /**
     * Vooraf uitgetekend en niet als SVG meegegeven. dompdf schaalt een SVG
     * met een verhouding die per plaatje anders uitvalt; een PNG met een vaste
     * maat komt eruit zoals hij erin gaat. De bestanden worden gemaakt met
     * scripts/invoice-icons.py.
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

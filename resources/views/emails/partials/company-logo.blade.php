@php
    $logo_src = isset($message) && $logo_file ? $message->embed($logo_file) : $logo_url;
@endphp
@if ($logo_src)
    <img src="{{ $logo_src }}" alt="{{ $company_name }}">
@elseif ($company_name)
    <span style="font-size:20px;font-weight:600;color:#2d3748;">{{ $company_name }}</span>
@endif

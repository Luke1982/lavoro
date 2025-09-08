<tr>
    <td class="header" style="padding:25px 0;text-align:center">
        @php($logo = config('app.mail_logo_url'))
        @if ($logo)
            <a href="{{ config('app.url') }}" style="display:inline-block;">
                <img src="{{ $logo }}" class="logo" alt="{{ config('app.name') }}"
                    style="max-width:180px; height:auto;">
            </a>
        @else
            <a href="{{ config('app.url') }}"
                style="display:inline-block; font-size:20px; font-weight:600; color:#2d3748; text-decoration:none;">
                {{ config('app.name') }}
            </a>
        @endif
    </td>
</tr>

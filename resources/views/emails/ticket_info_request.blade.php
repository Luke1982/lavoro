<!DOCTYPE html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $company_name ?? 'Aanvullende informatie' }}</title>
</head>

<body style="margin:0; padding:0; background-color:#f4f5f7;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
        style="background-color:#f4f5f7; padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                    style="max-width:600px; background-color:#ffffff; border-radius:8px; padding:32px;
                           font-family:Arial,Helvetica,sans-serif; color:#1f2933; font-size:15px; line-height:1.6;">
                    @php
                        /**
                         * Meegestuurd waar dat kan. $message bestaat alleen tijdens het
                         * echte versturen; een voorbeeldweergave valt terug op de url.
                         */
                        $logo_src = isset($message) && $logo_file
                            ? $message->embed($logo_file)
                            : $logo_url;
                    @endphp

                    @if ($logo_src)
                        <tr>
                            <td style="padding-bottom:24px;">
                                <img src="{{ $logo_src }}" alt="{{ $company_name }}" height="40"
                                    style="height:40px; width:auto; display:block; border:0;">
                            </td>
                        </tr>
                    @elseif ($company_name)
                        <tr>
                            <td style="padding-bottom:24px; font-size:18px; font-weight:bold;">
                                {{ $company_name }}
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td>{!! $body !!}</td>
                    </tr>

                    <tr>
                        <td style="padding:28px 0 8px 0;">
                            {{-- Een tabel en geen knop met randen: Outlook tekent alleen dit betrouwbaar. --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" bgcolor="#1d4ed8" style="border-radius:6px;">
                                        <a href="{{ $upload_url }}"
                                            style="display:inline-block; padding:14px 28px; font-family:Arial,Helvetica,sans-serif;
                                                   font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none;
                                                   border-radius:6px;">
                                            Informatie aanleveren
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-top:16px; font-size:12px; color:#6b7280;">
                            Werkt de knop niet? Kopieer dan deze link naar uw browser:<br>
                            <span style="word-break:break-all;">{{ $upload_url }}</span>
                            @if ($expires_on)
                                <br><br>Deze link werkt tot en met {{ $expires_on }}.
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>

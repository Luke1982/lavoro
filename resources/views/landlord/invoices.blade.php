@extends('landlord.layout')
@section('content')
<h2 style="margin:0 0 4px">Facturen &middot; {{ $tenant->name }}</h2>
<p class="muted" style="margin:0 0 18px"><a href="{{ route('landlord.edit', $tenant->id) }}">terug naar het abonnement</a></p>

<div class="card" style="max-width:100%">
    <h3>Eerstvolgende factuur</h3>
    <table>
        @foreach($preview['lines'] as $line)
            <tr><td>{{ $line['description'] }}</td><td style="text-align:right;width:140px">&euro; {{ number_format($line['amount_cents'] / 100, 2, ',', '.') }}</td></tr>
        @endforeach
        @if($preview['discount_cents'])
            <tr><td>Jaarkorting</td><td style="text-align:right;color:#b91c1c">&minus; &euro; {{ number_format($preview['discount_cents'] / 100, 2, ',', '.') }}</td></tr>
        @endif
        <tr><td>Netto</td><td style="text-align:right">&euro; {{ number_format($preview['total_cents'] / 100, 2, ',', '.') }}</td></tr>
        <tr><td>BTW {{ $preview['vat_percent'] }}%</td><td style="text-align:right">&euro; {{ number_format($preview['vat_cents'] / 100, 2, ',', '.') }}</td></tr>
        <tr><td><strong>Te betalen</strong></td><td style="text-align:right"><strong>&euro; {{ number_format($preview['gross_cents'] / 100, 2, ',', '.') }}</strong></td></tr>
    </table>
    @if($is_due)
        <form method="post" action="{{ route('landlord.invoice.issue', $tenant->id) }}" style="margin-top:14px">@csrf
            <button type="submit">Factuur aanmaken</button>
        </form>
    @else
        <p class="muted" style="margin-top:14px">
            Deze periode is al gefactureerd en er staat niets nieuws open. De volgende factuur kan
            vanaf <strong>{{ $next_period_starts_on->format('d-m-Y') }}</strong>, of eerder zodra er
            iets verandert &mdash; een pakketwissel of bijgekocht AI-tegoed.
        </p>
    @endif
</div>

<div class="card" style="max-width:100%;margin-top:20px">
    <h3>Aangemaakt</h3>
    @forelse($invoices as $invoice)
        <table style="margin-bottom:14px">
            <tr>
                <th>{{ $invoice->number }}</th>
                <th>{{ $invoice->issued_on->format('d-m-Y') }}</th>
                <th style="text-align:right">&euro; {{ number_format($invoice->gross_cents / 100, 2, ',', '.') }}</th>
                <th style="text-align:right;width:240px">
                    <a href="{{ route('landlord.invoice.pdf', [$tenant->id, $invoice->id]) }}">pdf</a> &middot;
                    <a href="{{ route('landlord.invoice.xml', [$tenant->id, $invoice->id]) }}">xml</a>
                    @if($invoice->mailed_at)
                        &middot; <span class="muted">verstuurd {{ $invoice->mailed_at->format('d-m-Y H:i') }}</span>
                    @else
                        &middot;
                        <form method="post" style="display:inline"
                            action="{{ route('landlord.invoice.mail', [$tenant->id, $invoice->id]) }}">@csrf
                            <button type="submit" class="linkish">versturen</button>
                        </form>
                    @endif
                </th>
            </tr>
            @foreach($invoice->lines as $line)
                <tr><td colspan="2" class="muted">{{ $line->description }}</td>
                    <td style="text-align:right" class="muted">&euro; {{ number_format($line->amount_cents / 100, 2, ',', '.') }}</td></tr>
            @endforeach
            @if($invoice->mail_error)
                <tr><td colspan="4" style="color:#b91c1c">Versturen mislukt: {{ $invoice->mail_error }}</td></tr>
            @endif
        </table>
    @empty
        <p class="muted">Nog geen facturen.</p>
    @endforelse
</div>
@endsection

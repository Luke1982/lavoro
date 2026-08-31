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
        <tr><td><strong>Totaal</strong></td><td style="text-align:right"><strong>&euro; {{ number_format($preview['total_cents'] / 100, 2, ',', '.') }}</strong></td></tr>
    </table>
    <form method="post" action="{{ route('landlord.invoice.issue', $tenant->id) }}" style="margin-top:14px">@csrf
        <button type="submit">Factuur aanmaken</button>
    </form>
</div>

<div class="card" style="max-width:100%;margin-top:20px">
    <h3>Verstuurd</h3>
    @forelse($invoices as $invoice)
        <table style="margin-bottom:14px">
            <tr>
                <th>{{ $invoice->number }}</th>
                <th>{{ $invoice->issued_on->format('d-m-Y') }}</th>
                <th style="text-align:right">&euro; {{ number_format($invoice->total_cents / 100, 2, ',', '.') }}</th>
            </tr>
            @foreach($invoice->lines as $line)
                <tr><td colspan="2" class="muted">{{ $line->description }}</td>
                    <td style="text-align:right" class="muted">&euro; {{ number_format($line->amount_cents / 100, 2, ',', '.') }}</td></tr>
            @endforeach
        </table>
    @empty
        <p class="muted">Nog geen facturen.</p>
    @endforelse
</div>
@endsection

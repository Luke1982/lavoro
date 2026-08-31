@extends('landlord.layout')
@section('content')
<h2 style="margin:0 0 4px">Incasso</h2>
<p class="muted" style="margin:0 0 18px">
    Facturen van klanten met een machtiging die nog niet in een incassobestand zaten.
    Het bestand (SEPA-XML, pain.008) lever je aan via ASN Online Bankieren.
</p>

@if(blank($issuer['incassant_id'] ?? null))
    <div class="card" style="max-width:100%;border-left:4px solid #b91c1c">
        Er is nog geen incassant-ID ingesteld. Dat nummer geeft de bank uit bij het
        incassocontract; zonder dat nummer weigert de bank het bestand. Vul het in bij
        <a href="{{ route('landlord.catalogue') }}">Catalogus &rarr; Facturatie</a>.
    </div>
@endif

<div class="card" style="max-width:100%;margin-top:14px">
    @if($invoices->isEmpty())
        <p class="muted">Niets te incasseren.</p>
    @else
        <form method="post" action="{{ route('landlord.collections.export') }}">@csrf
            <table>
                <tr>
                    <th></th><th>Factuur</th><th>Klant</th><th>Machtiging</th>
                    <th style="text-align:right">Bedrag</th>
                </tr>
                @foreach($invoices as $invoice)
                    <tr>
                        <td style="width:24px"><input type="checkbox" name="invoices[]" value="{{ $invoice->id }}" checked></td>
                        <td>{{ $invoice->number }}</td>
                        <td>{{ $invoice->tenant->name }}</td>
                        <td class="muted">{{ $invoice->tenant->mandate_reference }} &middot; {{ $invoice->tenant->iban }}</td>
                        <td style="text-align:right">&euro; {{ number_format($invoice->gross_cents / 100, 2, ',', '.') }}</td>
                    </tr>
                @endforeach
            </table>
            <p style="margin:14px 0 0">
                Incassodatum
                <input type="date" name="collect_on" value="{{ $collect_on }}" style="width:160px">
                <button type="submit" style="margin-left:10px">Incassobestand maken</button>
            </p>
            <p class="muted" style="margin:6px 0 0">
                Na het maken staan deze facturen op ge&iuml;ncasseerd en komen ze hier niet terug.
            </p>
        </form>
    @endif
</div>
@endsection

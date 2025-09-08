{{-- Let op: geen inspringing gebruiken; spaties vooraan maken code blocks in Markdown mails. --}}
@component('mail::message')
    # Werkbon #{{ $serviceOrder->id }}

    Beste {{ $serviceOrder->customer->contact_person ?? $serviceOrder->customer->name }},

    In de bijlage vindt u de PDF van de uitgevoerde werkzaamheden (werkbon).

    @php $desc = trim((string) ($serviceOrder->description ?? '')); @endphp
    **Datum:** {{ optional($serviceOrder->created_at)->format('d-m-Y') }} **Klant:** {{ $serviceOrder->customer->name }}
    **Omschrijving:** @if ($desc !== '')
        {!! nl2br(e($desc)) !!}
    @else
        Geen omschrijving opgegeven.
    @endif

    Met vriendelijke groet,

    {{ config('app.name') }}
@endcomponent

@extends('landlord.layout')
@section('content')
<div class="card">
    <h2 style="margin-top:0">{{ $tenant->name }}</h2>
    <p class="muted">{{ $tenant->getInternal('db_name') }} &middot; {{ $tenant->id }}</p>
    <form method="post" action="{{ route('landlord.update', $tenant->id) }}">
        @csrf @method('put')
        <label>Pakket</label>
        <select name="package_key">
            <option value="">— geen —</option>
            @foreach($packages as $package)
                <option value="{{ $package->key }}" @selected($tenant->package_key === $package->key)>
                    {{ $package->name }} (&euro; {{ number_format($package->price_cents / 100, 2, ',', '.') }})
                </option>
            @endforeach
        </select>
        <div class="grid">
            <div><label>Extra buitendienst</label><input type="number" name="extra_field_seats" min="0" value="{{ $tenant->extra_field_seats }}"></div>
            <div><label>Extra binnendienst</label><input type="number" name="extra_office_seats" min="0" value="{{ $tenant->extra_office_seats }}"></div>
            <div><label>Opslag (GB)</label><input type="number" name="storage_limit_gb" min="0" value="{{ $tenant->storage_limit_gb }}"></div>
            <div>
                <label>AI-limiet per maand (&euro;)</label>
                <input type="number" step="0.01" min="0" name="ai_allowance_euro"
                    value="{{ $ai_is_default ? '' : number_format($ai_allowance_euro, 2, '.', '') }}"
                    placeholder="{{ number_format($ai_allowance_euro, 2, ',', '') }} (standaard)">
                <p class="muted" style="margin:4px 0 0">
                    Deze maand verbruikt: &euro; {{ number_format($ai_spent_euro, 2, ',', '.') }}
                    van &euro; {{ number_format($ai_allowance_euro, 2, ',', '.') }}.
                    Leeg laten volgt de standaard uit de catalogus.
                </p>
            </div>
        </div>
        <label>Vaste maandprijs in centen <span class="muted">(leeg = berekenen)</span></label>
        <input type="number" name="price_override_cents" min="0" value="{{ $tenant->price_override_cents }}">
        <label>Modules</label>
        @foreach($modules as $module)
            <div><label style="font-weight:400">
                <input type="checkbox" name="modules[]" value="{{ $module->key }}" @checked($tenant->hasModule($module->key))>
                {{ $module->name }} @if($module->price_cents) <span class="muted">&euro; {{ number_format($module->price_cents / 100, 2, ',', '.') }}</span>@endif
            </label></div>
        @endforeach
        <p><button type="submit">Opslaan</button> &nbsp; <a href="{{ route('landlord.index') }}">annuleren</a></p>
    </form>
</div>
@endsection

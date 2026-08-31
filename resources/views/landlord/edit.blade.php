@extends('landlord.layout')
@section('content')
<h2 style="margin:0 0 4px">{{ $tenant->name }}</h2>
<p class="muted" style="margin:0 0 18px">{{ $tenant->getInternal('db_name') }} &middot; {{ $tenant->id }}</p>

<div class="cols">
<div class="card" style="max-width:100%">
    <h3>Abonnement</h3>
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
                    @if ($ai_topup_euro > 0)
                        Bijgekocht tegoed: &euro; {{ number_format($ai_topup_euro, 2, ',', '.') }}.
                    @endif
                    Leeg laten volgt de standaard uit de catalogus.
                </p>
            </div>
        </div>
        <div style="background:#f8fafc;border:1px solid var(--line);border-radius:6px;padding:12px;margin:14px 0">
            <div>Berekend: &euro; {{ number_format($sub->beforeDiscountCents() / 100, 2, ',', '.') }}</div>
            @if($sub->discountCents())
                <div style="color:#b91c1c">Korting: &minus; &euro; {{ number_format($sub->discountCents() / 100, 2, ',', '.') }}</div>
            @endif
            <div style="font-size:18px;font-weight:700;margin-top:4px">
                Totaal: &euro; {{ number_format($sub->monthlyTotalCents() / 100, 2, ',', '.') }} per maand
            </div>
        </div>

        <label>Korting</label>
        @php($type = $tenant->discount_percent ? 'percent' : ($tenant->discount_cents ? 'euro' : 'none'))
        <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
            <label style="font-weight:400"><input type="radio" name="discount_type" value="none" @checked($type === 'none')> geen</label>
            <label style="font-weight:400"><input type="radio" name="discount_type" value="euro" @checked($type === 'euro')> bedrag</label>
            <input type="number" step="0.01" min="0" name="discount_euro" style="width:110px"
                value="{{ $tenant->discount_cents ? number_format($tenant->discount_cents / 100, 2, '.', '') : '' }}" placeholder="&euro; p/m">
            <label style="font-weight:400"><input type="radio" name="discount_type" value="percent" @checked($type === 'percent')> percentage</label>
            <input type="number" min="0" max="100" name="discount_percent" style="width:80px"
                value="{{ $tenant->discount_percent }}" placeholder="%">
        </div>

        <label>Vaste maandprijs (&euro;) <span class="muted">(leeg = berekenen)</span></label>
        <input type="number" step="0.01" name="price_override_euro" min="0"
            value="{{ $tenant->price_override_cents ? number_format($tenant->price_override_cents / 100, 2, '.', '') : '' }}">
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

<div>
<div class="card" style="max-width:100%">
    <h3>Coupon</h3>
    @if($tenant->coupon_discount_percent)
        <p>
            {{ $tenant->coupon_discount_percent }}% korting tot
            {{ \Carbon\Carbon::parse($tenant->coupon_discount_until)->format('d-m-Y') }}
            @if($reseller) &middot; via {{ $reseller->name }} ({{ $reseller->commission_percent }}% commissie) @endif
        </p>
        <p class="muted">Deze maand commissie: &euro; {{ number_format($sub->commissionCents() / 100, 2, ',', '.') }}</p>
    @else
        <form method="post" action="{{ route('landlord.coupon.redeem', $tenant->id) }}">@csrf
            <label>Couponcode</label>
            <div style="display:flex;gap:8px"><input type="text" name="code" placeholder="ZOMER2026" style="max-width:220px"><button type="submit">Verzilveren</button></div>
        </form>
    @endif
</div>

<div class="card" style="max-width:100%;margin-top:20px">
    <h3>AI bijkopen</h3>
    <p class="muted">
        Eenmalig en niet aan een maand gebonden: wat er niet op gaat blijft staan.
        Het maandtegoed gaat er eerst af.<br>
        Tarief: &euro; {{ number_format($topup_rate / 100, 2, ',', '.') }} betaald geeft &euro; 1,00 aan tegoed.
    </p>
    <form method="post" action="{{ route('landlord.topup', $tenant->id) }}">
        @csrf
        <div class="grid">
            <div><label>Bedrag betaald (&euro;)</label><input type="number" step="0.01" min="0.01" name="paid_euro" placeholder="10.00"></div>
            <div><label>Notitie</label><input type="text" name="note" placeholder="factuurnummer"></div>
        </div>
        <p><button type="submit">Toevoegen</button></p>
    </form>
    @if($topups->isNotEmpty())
        <table>
            <tr><th>Datum</th><th>Betaald</th><th>Tegoed</th><th>Notitie</th></tr>
            @foreach($topups as $t)
                <tr>
                    <td>{{ $t->created_at->format('d-m-Y') }}</td>
                    <td>&euro; {{ number_format($t->paid_cents / 100, 2, ',', '.') }}</td>
                    <td>&euro; {{ number_format($t->granted_micros / 1000000, 2, ',', '.') }}</td>
                    <td class="muted">{{ $t->note }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</div>
</div>
</div>
@endsection

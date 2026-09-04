@extends('landlord.layout')
@section('content')
<h2 style="margin:0 0 4px">{{ $tenant->name }}</h2>
<p class="muted" style="margin:0 0 18px">{{ $tenant->getInternal('db_name') }} &middot; {{ $tenant->id }}</p>

<div class="cols">
<div class="card" style="max-width:100%">
    <h3>Abonnement</h3>
    <form method="post" action="{{ route('landlord.update', $tenant->id) }}">
        @csrf @method('put')
        <div class="grid">
            <div><label>Startdatum abonnement</label>
                <input type="date" name="subscription_started_on" value="{{ optional($tenant->subscription_started_on)->format('Y-m-d') ?? $tenant->subscription_started_on }}"></div>
            <div><label>Facturatie</label>
                <select name="billing_period">
                    <option value="monthly" @selected($tenant->billing_period !== 'yearly')>Per maand</option>
                    <option value="yearly" @selected($tenant->billing_period === 'yearly')>Per jaar (2% korting)</option>
                </select></div>
        </div>

        <label>Pakket</label>
        <select name="package_key">
            <option value="">— geen —</option>
            @foreach($packages as $package)
                <option value="{{ $package->key }}" @selected($tenant->package_key === $package->key)>
                    {{ $package->name }} (@euro($package->price_cents))
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
                    value="{{ $ai_is_default ? '' : \App\Support\Money::input($ai_allowance_cents) }}"
                    placeholder="{{ \App\Support\Money::human($ai_allowance_cents) }} (standaard)">
                <p class="muted" style="margin:4px 0 0">
                    Deze maand verbruikt: @euro($ai_spent_cents)
                    van @euro($ai_allowance_cents).
                    @if ($ai_topup_cents > 0)
                        Bijgekocht tegoed: @euro($ai_topup_cents).
                    @endif
                    Leeg laten volgt de standaard uit de catalogus.
                </p>
            </div>
        </div>
        <div style="background:#f8fafc;border:1px solid var(--line);border-radius:6px;padding:12px;margin:14px 0">
            <div>Berekend: @euro($sub->beforeDiscountCents())</div>
            @if($sub->discountCents())
                <div style="color:#b91c1c">Korting: &minus; @euro($sub->discountCents())</div>
            @endif
            <div style="font-size:18px;font-weight:700;margin-top:4px">
                Totaal: @euro($sub->monthlyTotalCents()) per maand
            </div>
        </div>

        <label>Korting</label>
        @php($type = $tenant->discount_percent ? 'percent' : ($tenant->discount_cents ? 'euro' : 'none'))
        <div class="choice">
            <label><input type="radio" name="discount_type" value="none" @checked($type === 'none')>
                <span class="choice-label">Geen korting</span></label>

            <label><input type="radio" name="discount_type" value="euro" @checked($type === 'euro')>
                <span class="choice-label">Vast bedrag</span>
                <input type="number" step="0.01" min="0" name="discount_euro" placeholder="0,00"
                    value="{{ \App\Support\Money::input($tenant->discount_cents) }}">
                <span class="muted">&euro; per maand</span></label>

            <label><input type="radio" name="discount_type" value="percent" @checked($type === 'percent')>
                <span class="choice-label">Percentage</span>
                <input type="number" min="0" max="100" name="discount_percent" placeholder="0"
                    value="{{ $tenant->discount_percent }}">
                <span class="muted">%</span></label>
        </div>

        <label>Vaste maandprijs (&euro;) <span class="muted">(leeg = berekenen)</span></label>
        <input type="number" step="0.01" name="price_override_euro" min="0"
            value="{{ \App\Support\Money::input($tenant->price_override_cents) }}">
        <label>Factuurgegevens</label>
        <div class="grid">
            <div><input type="text" name="invoice_address" placeholder="Straat en nummer" value="{{ $tenant->invoice_address }}"></div>
            <div><input type="email" name="invoice_email" placeholder="Factuur-e-mail" value="{{ $tenant->invoice_email }}"></div>
            <div><input type="text" name="invoice_postcode" placeholder="Postcode" value="{{ $tenant->invoice_postcode }}"></div>
            <div><input type="text" name="invoice_city" placeholder="Plaats" value="{{ $tenant->invoice_city }}"></div>
            <div><input type="text" name="vat_number" placeholder="BTW-nummer" value="{{ $tenant->vat_number }}"></div>
            <div><input type="text" name="coc_number" placeholder="KvK-nummer" value="{{ $tenant->coc_number }}"></div>
        </div>

        <label>Betaling</label>
        <div class="choice">
            <label><input type="radio" name="payment_method" value="transfer" @checked($tenant->payment_method !== 'direct_debit')>
                <span class="choice-label">Overboeking</span>
                <span class="muted">de klant maakt zelf over</span></label>
            <label><input type="radio" name="payment_method" value="direct_debit" @checked($tenant->payment_method === 'direct_debit')>
                <span class="choice-label">Incasso</span>
                <span class="muted">automatisch afschrijven met machtiging</span></label>
        </div>
        <div class="grid">
            <div><input type="text" name="iban" placeholder="IBAN" value="{{ $tenant->iban }}"></div>
            <div><input type="text" name="account_holder" placeholder="Naam rekeninghouder" value="{{ $tenant->account_holder }}"></div>
            <div><input type="text" name="mandate_reference" placeholder="Machtigingskenmerk" value="{{ $tenant->mandate_reference }}" maxlength="35"></div>
            <div><input type="date" name="mandate_signed_on" placeholder="Getekend op" value="{{ $tenant->mandate_signed_on }}"></div>
        </div>
        @error('iban')<p style="color:#b91c1c;margin:4px 0 0">{{ $message }}</p>@enderror

        <label>Modules</label>
        @foreach($modules as $module)
            <div><label style="font-weight:400">
                <input type="checkbox" name="modules[]" value="{{ $module->key }}" @checked($tenant->hasModule($module->key))>
                {{ $module->name }} @if($module->price_cents) <span class="muted">@euro($module->price_cents)</span>@endif
            </label></div>
        @endforeach
        <p><button type="submit">Opslaan</button> &nbsp; <a href="{{ route('landlord.index') }}">annuleren</a></p>
    </form>
</div>

<div>
<div class="card" style="max-width:100%">
    <h3>Facturatie</h3>
    <p>
        Volgende factuur: <strong>@euro($invoicer->preview()['total_cents'])</strong>
        <span class="muted">({{ $tenant->billing_period === 'yearly' ? 'per jaar' : 'per maand' }})</span>
    </p>
    @php($pending = $invoicer->pendingCharges())
    @if($pending->isNotEmpty())
        <p class="muted">Nog te verrekenen:</p>
        <table>
            @foreach($pending as $charge)
                <tr><td>{{ $charge->description }}</td><td style="text-align:right">@euro($charge->amount_cents)</td></tr>
            @endforeach
        </table>
    @endif
    <p><a href="{{ route('landlord.invoices', $tenant->id) }}">Facturen bekijken</a></p>
</div>

<div class="card" style="max-width:100%;margin-top:20px">
    <h3>Coupon</h3>
    @if($tenant->coupon_discount_percent)
        <p>
            {{ $tenant->coupon_discount_percent }}% korting tot
            {{ \Carbon\Carbon::parse($tenant->coupon_discount_until)->format('d-m-Y') }}
            @if($reseller) &middot; via {{ $reseller->name }} ({{ $reseller->commission_percent }}% commissie) @endif
        </p>
        <p class="muted">Deze maand commissie: @euro($sub->commissionCents())</p>
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
        Tarief: @euro($topup_rate) betaald geeft &euro; 1,00 aan tegoed.
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
                    <td>@euro($t->paid_cents)</td>
                    <td>@euro(\App\Support\Money::fromMicros($t->granted_micros))</td>
                    <td class="muted">{{ $t->note }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</div>

<div class="card" style="max-width:100%;margin-top:18px">
    <h3>Superbeheerders</h3>
    <p class="muted" style="margin:0 0 10px">
        Accounts van MajorLabel binnen deze klant. Mogen alles, langs elke
        rechtencontrole heen. De klant ziet deze rol nergens en kan hem niet
        toekennen.
    </p>

    @if(count($superadmins))
        <table>
            @foreach($superadmins as $admin)
                <tr>
                    <td>{{ $admin['name'] }} <span class="muted">{{ $admin['email'] }}</span></td>
                    <td style="text-align:right;width:110px">
                        <form method="post" action="{{ route('landlord.superadmin.destroy', [$tenant->id, $admin['id']]) }}">
                            @csrf @method('delete')
                            <button type="submit" class="linkish">verwijderen</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </table>
    @else
        <p class="muted">Nog geen superbeheerder voor deze klant.</p>
    @endif

    <form method="post" action="{{ route('landlord.superadmin.store', $tenant->id) }}" style="margin-top:12px">@csrf
        <div class="grid">
            <div><input type="email" name="email" placeholder="e-mailadres" required></div>
            <div><input type="text" name="password" placeholder="wachtwoord (leeg = genereren)"></div>
        </div>
        <p style="margin:10px 0 0"><button type="submit">Superbeheerder toevoegen</button></p>
    </form>
</div>
</div>

@if($unreachable)
    <div class="flash flash-bad" style="margin-top:22px">
        De database van deze klant is niet bereikbaar, dus een deel van dit scherm
        blijft leeg: <span class="warn">{{ $unreachable }}</span><br>
        Hieronder kun je hem opruimen.
    </div>
@endif

<div class="card" style="max-width:100%;margin-top:22px;border-color:#fca5a5">
    <h3 class="warn">Klant verwijderen</h3>
    <p class="muted" style="margin:0 0 10px">
        Dit gooit de database van {{ $tenant->name }} weg, het databaseaccount, de
        bestanden en de centrale rijen. Er is geen weg terug en er is geen
        prullenbak. Tik de naam letterlijk over om te bevestigen.
    </p>
    <form method="post" action="{{ route('landlord.tenant.destroy', $tenant->id) }}">
        @csrf @method('delete')
        <div class="grid">
            <div>
                <label>Naam van de klant</label>
                <input type="text" name="confirm_name" placeholder="{{ $tenant->name }}"
                    autocomplete="off" required>
            </div>
            <div style="display:flex;align-items:flex-end">
                <button type="submit" style="background:#b91c1c">Definitief verwijderen</button>
            </div>
        </div>
    </form>
</div>
</div>

@endsection

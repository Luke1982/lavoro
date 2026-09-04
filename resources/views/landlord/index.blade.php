@extends('landlord.layout')
@section('content')
<p class="muted">{{ $rows->count() }} tenants &middot; samen @euro($monthly) per maand</p>
<table>
    <tr><th>Naam</th><th>Pakket</th><th>Buiten</th><th>Binnen</th><th>Opslag</th><th>Per maand</th><th></th></tr>
    @foreach($rows as $row)
        <tr>
            <td>
                    <strong>{{ $row['tenant']->name }}</strong><br>
                    <span class="muted">{{ $row['tenant']->getInternal('db_name') }}</span>
                    @if($row['busy'])<br><span class="muted">wordt nu aangemaakt…</span>@endif
                    @if($row['broken'])<br><span class="warn">{{ $row['broken'] }}</span>@endif
                </td>
            <td>{{ $row['tenant']->package_key ?? '—' }}</td>
            <td class="{{ $row['field'] > $row['field_limit'] ? 'warn' : '' }}">{{ $row['field'] }}/{{ $row['field_limit'] }}</td>
            <td class="{{ $row['office'] > $row['office_limit'] ? 'warn' : '' }}">{{ $row['office'] }}/{{ $row['office_limit'] }}</td>
            <td class="{{ $row['used_gb'] > $row['tenant']->storage_limit_gb ? 'warn' : '' }}">{{ $row['used_gb'] }} / {{ $row['tenant']->storage_limit_gb }} GB</td>
            <td>@euro($row['total'])</td>
            <td><a href="{{ route('landlord.edit', $row['tenant']->id) }}">bewerken</a></td>
        </tr>
    @endforeach
</table>

@if($requests->isNotEmpty())
    <div class="card" style="max-width:100%;margin-top:22px">
        <h3>Bezig</h3>
        <p class="muted" style="margin:0 0 10px">
            Aanmaken en verwijderen doet de provisioner op de achtergrond. Blijft een regel
            hier staan, dan draait die worker niet.
        </p>
        <table>
            @foreach($requests as $request)
                <tr>
                    <td style="width:110px">
                        @if($request->status === 'failed')
                            <span class="warn">mislukt</span>
                        @else
                            <span class="muted">{{ $request->status === 'running' ? 'bezig' : 'in de wacht' }}</span>
                        @endif
                    </td>
                    <td>
                        {{ $request->action === 'delete' ? 'Verwijderen' : 'Aanmaken' }}: <strong>{{ $request->name }}</strong>
                        @if($request->error)<br><span class="warn">{{ $request->error }}</span>@endif
                    </td>
                    <td style="text-align:right;width:120px">
                        @if($request->status === 'failed')
                            <form method="post" action="{{ route('landlord.provisioning.destroy', $request->id) }}">
                                @csrf @method('delete')
                                <button type="submit" class="linkish">weghalen</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
@endif

@if($passwords->isNotEmpty())
    <div class="card" style="max-width:100%;margin-top:22px">
        <h3>Wachtwoorden om door te geven</h3>
        <p class="muted" style="margin:0 0 10px">
            Van een nieuwe tenant. Geef het door en wis het daarna; het staat hier
            leesbaar zolang het er staat.
        </p>
        <table>
            @foreach($passwords as $request)
                <tr>
                    <td>
                        <strong>{{ $request->name }}</strong><br>
                        {{ $request->email }} &middot; <code>{{ $request->generated_password }}</code>
                    </td>
                    <td style="text-align:right;width:120px">
                        <form method="post" action="{{ route('landlord.provisioning.forget-password', $request->id) }}">
                            @csrf @method('delete')
                            <button type="submit" class="linkish">wissen</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
@endif

<div class="card" style="max-width:100%;margin-top:22px">
    <h3>Nieuwe tenant</h3>
    <form method="post" action="{{ route('landlord.tenant.store') }}">@csrf
        <div class="grid">
            <div>
                <label>Bedrijfsnaam</label>
                <input type="text" name="name" value="{{ old('name') }}" required>
                <span class="muted">De databasenaam volgt hieruit en ligt daarna vast.</span>
                @error('name')<p class="warn">{{ $message }}</p>@enderror
            </div>
            <div>
                <label>E-mail van de eerste beheerder</label>
                <input type="email" name="email" value="{{ old('email') }}" required>
                @error('email')<p class="warn">{{ $message }}</p>@enderror
            </div>
        </div>

        <label>Pakket</label>
        <select name="package_key">
            @foreach($packages as $package)
                <option value="{{ $package->key }}" @selected(old('package_key') === $package->key)>
                    {{ $package->name }} &mdash; @euro($package->price_cents)
                </option>
            @endforeach
        </select>
        @error('package_key')<p class="warn">{{ $message }}</p>@enderror

        <label>Modules</label>
        @foreach($modules as $module)
            <div><label style="font-weight:400">
                <input type="checkbox" name="modules[]" value="{{ $module->key }}"
                    @checked(in_array($module->key, old('modules', []), true))>
                {{ $module->name }}
                <span class="muted">@euro($module->price_cents)</span>
            </label></div>
        @endforeach

        <p style="margin-top:14px"><button type="submit">Aanmaken</button></p>
        <p class="muted" style="margin:0">
            Het wachtwoord van de beheerder wordt gegenereerd en hierboven getoond zodra de tenant klaar is.
        </p>
    </form>
</div>

{{--
    Aanmaken en verwijderen doet de provisioner op de achtergrond, dus het
    scherm klopt al niet meer zodra je het ziet. Daarom vraagt het elke drie
    seconden of er iets veranderd is, en haalt het zichzelf op zodra dat zo is.

    Alleen een vingerafdruk over de lijn, geen inhoud: dan hoeft dit niet te
    weten hoe het scherm eruitziet en blijft er één plek waar dat staat. En
    alleen zolang er iets loopt -- een stilstaand paneel hoort niets te vragen.
--}}
@if($requests->isNotEmpty())
<script>
    (() => {
        const url = @json(route('landlord.provisioning.status'));
        /** Meegekregen bij het opbouwen, zodat de eerste navraag al kan vergelijken. */
        let known = @json($signature);
        let timer = null;

        const poll = async () => {
            try {
                /**
                 * Met een teller erachter, zodat elke vraag een eigen adres
                 * heeft. Een service worker die al draait bewaart antwoorden op
                 * adres; zonder dit kreeg het paneel eeuwig het eerste antwoord
                 * terug en ververste het nooit. De worker laat /beheer nu met
                 * rust, maar de oude draait in een browser gewoon door tot hij
                 * zichzelf vervangt.
                 */
                const response = await fetch(url + '?t=' + Date.now(), {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });

                if (!response.ok) {
                    return stop();
                }

                const { signature, busy } = await response.json();

                if (signature !== known) {
                    /**
                     * Eén keer verversen per toestand. Blijft er op de
                     * achtergrond iets veranderen -- werk dat steeds opnieuw
                     * mislukt bijvoorbeeld -- dan zou het scherm anders blijven
                     * herladen en valt er niets meer te lezen, ook niet de
                     * melding die vertelt wat er aan de hand is.
                     */
                    if (sessionStorage.getItem('lavoro-herladen') === signature) {
                        return stop();
                    }

                    sessionStorage.setItem('lavoro-herladen', signature);

                    return window.location.reload();
                }

                /* Klaar is klaar: doorvragen terwijl er niets loopt belast alleen. */
                if (!busy) {
                    stop();
                }
            } catch {
                /* Netwerk even weg is geen reden om te blijven hameren. */
                stop();
            }
        };

        const stop = () => timer && clearInterval(timer);

        poll();
        timer = setInterval(poll, 3000);
    })();
</script>
@endif

@endsection

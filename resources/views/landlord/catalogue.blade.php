@extends('landlord.layout')
@section('content')
<h3>Pakketten</h3>
<table>
    <tr><th>Sleutel</th><th>Naam</th><th>Buiten</th><th>Binnen</th><th>Prijs (ct)</th><th>Extra buiten</th><th>Extra binnen</th><th>In gebruik</th><th></th></tr>
    @foreach($packages as $p)
    <tr>
        <form method="post" action="{{ route('landlord.package.update', $p->id) }}">@csrf @method('put')
        <td><code>{{ $p->key }}</code></td>
        <td><input type="text" name="name" value="{{ $p->name }}" style="width:120px"></td>
        <td><input type="number" name="field_seats" value="{{ $p->field_seats }}" style="width:70px"></td>
        <td><input type="number" name="office_seats" value="{{ $p->office_seats }}" style="width:70px"></td>
        <td><input type="number" name="price_cents" value="{{ $p->price_cents }}" style="width:90px"></td>
        <td><input type="number" name="extra_field_cents" value="{{ $p->extra_field_cents }}" style="width:90px"></td>
        <td><input type="number" name="extra_office_cents" value="{{ $p->extra_office_cents }}" style="width:90px"></td>
        <td class="muted">{{ $usage[$p->key] ?? 0 }} tenant(s)</td>
        <td><button>Opslaan</button></td>
        </form>
    </tr>
    @endforeach
</table>

<h3>Modules</h3>
<table>
    <tr><th>Sleutel</th><th>Naam</th><th>Prijs (ct)</th><th></th></tr>
    @foreach($modules as $m)
    <tr>
        <form method="post" action="{{ route('landlord.module.update', $m->id) }}">@csrf @method('put')
        <td><code>{{ $m->key }}</code></td>
        <td><input type="text" name="name" value="{{ $m->name }}" style="width:180px"></td>
        <td><input type="number" name="price_cents" value="{{ $m->price_cents }}" style="width:90px"></td>
        <td><button>Opslaan</button></td>
        </form>
    </tr>
    @endforeach
</table>

<h3>Bundels</h3>
<table>
    <tr><th>Naam</th><th>Modules</th><th>Prijs (ct)</th></tr>
    @foreach($bundles as $b)
        <tr><td>{{ $b->name }}</td><td class="muted">{{ implode(', ', $b->module_keys) }}</td><td>{{ $b->price_cents }}</td></tr>
    @endforeach
</table>

<h3>Instellingen</h3>
<table>
    <tr><th>Sleutel</th><th>Waarde</th><th></th></tr>
    @foreach($settings as $s)
    <tr>
        <form method="post" action="{{ route('landlord.setting.update', $s->id) }}">@csrf @method('put')
        <td><code>{{ $s->key }}</code></td>
        <td><input type="number" name="value" value="{{ $s->value }}" style="width:140px"></td>
        <td><button>Opslaan</button></td>
        </form>
    </tr>
    @endforeach
</table>
@endsection

@extends('landlord.layout')
@section('content')
<p class="muted">{{ $rows->count() }} tenants &middot; samen &euro; {{ number_format($monthly / 100, 2, ',', '.') }} per maand</p>
<table>
    <tr><th>Naam</th><th>Pakket</th><th>Buiten</th><th>Binnen</th><th>Opslag</th><th>Per maand</th><th></th></tr>
    @foreach($rows as $row)
        <tr>
            <td><strong>{{ $row['tenant']->name }}</strong><br><span class="muted">{{ $row['tenant']->getInternal('db_name') }}</span></td>
            <td>{{ $row['tenant']->package_key ?? '—' }}</td>
            <td class="{{ $row['field'] > $row['field_limit'] ? 'warn' : '' }}">{{ $row['field'] }}/{{ $row['field_limit'] }}</td>
            <td class="{{ $row['office'] > $row['office_limit'] ? 'warn' : '' }}">{{ $row['office'] }}/{{ $row['office_limit'] }}</td>
            <td class="{{ $row['used_gb'] > $row['tenant']->storage_limit_gb ? 'warn' : '' }}">{{ $row['used_gb'] }} / {{ $row['tenant']->storage_limit_gb }} GB</td>
            <td>&euro; {{ number_format($row['total'] / 100, 2, ',', '.') }}</td>
            <td><a href="{{ route('landlord.edit', $row['tenant']->id) }}">bewerken</a></td>
        </tr>
    @endforeach
</table>
@endsection

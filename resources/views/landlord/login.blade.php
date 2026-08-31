@extends('landlord.layout')
@section('content')
<div class="card">
    <h2 style="margin-top:0">Inloggen</h2>
    <form method="post" action="{{ route('landlord.login.post') }}">
        @csrf
        <label>E-mailadres</label>
        <input type="email" name="email" value="{{ old('email') }}" autofocus required>
        <label>Wachtwoord</label>
        <input type="password" name="password" required>
        <p><button type="submit">Inloggen</button></p>
    </form>
</div>
@endsection

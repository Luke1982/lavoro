<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- config() en niet env(): met een gecachete configuratie geeft env() zijn
         standaardwaarde terug, en dat is letterlijk 'Laravel'. --}}
    <title inertia>{{ config('app.name') }} Beheer</title>
    @vite(['resources/css/app.css', 'resources/js/landlord.js'])
    @inertiaHead
</head>
<body class="bg-slate-50 text-slate-900">
    @inertia
</body>
</html>

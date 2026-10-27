<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="application-name" content="{{ App\Support\PageTitle::brand() }}">
    <title inertia>{{ App\Support\PageTitle::brand() }} - Beheer</title>
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @vite(['resources/css/app.css', 'resources/js/landlord.js'])
    @inertiaHead
</head>
<body class="bg-slate-50 text-slate-900">
    @inertia
</body>
</html>

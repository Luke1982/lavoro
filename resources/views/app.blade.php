<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">

<head>
    <meta charset="utf-8">
    {{--
        viewport-fit=cover laat env(safe-area-inset-*) pas echt een waarde
        krijgen; zonder dat is die nul en valt de balk onderaan onder de
        home-indicator. interactive-widget zorgt dat het toetsenbord de pagina
        verkleint in plaats van eroverheen te schuiven.
    --}}
    <meta name="viewport"
        content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">

    {{-- "Lavoro - <module> - <tenant>", built in one place; app.js applies it after every visit. --}}
    <title inertia>{{ data_get($page, 'props.title', App\Support\PageTitle::brand()) }}</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
@unless (app()->isLocal())
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/service-worker.js');
            });
        }
    </script>
@endunless

<body class="h-full">
    @inertia
</body>

</html>

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

    {{--
        config() en niet env(): zodra config:cache heeft gedraaid geeft env()
        buiten de configuratiebestanden zijn standaardwaarde terug, en dat is
        letterlijk 'Laravel'. In productie stond dat dus in de titelbalk.

        De klantnaam komt uit de gedeelde props; het onderdeel zet app.js erbij
        zodra bekend is welke pagina er staat.
    --}}
    <title inertia>{{ collect([config('app.name'), data_get($page, 'props.tenant.name')])
        ->filter()->implode(' - ') }}</title>
    <link rel="manifest" href="/manifest.json">

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

<!DOCTYPE html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>Onderhoud — Lavoro</title>
    <link rel="icon" href="/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --blue: #2563ff;
            --green: #c6ff00;
            --dark: #081020;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
            margin: 0;
        }

        body {
            font-family: 'Sora', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--dark);
            color: #fff;
            -webkit-font-smoothing: antialiased;
        }

        .bg {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            z-index: 0;
        }

        .veil {
            position: fixed;
            inset: 0;
            background: radial-gradient(120% 90% at 50% 0%, rgba(8, 16, 32, .35) 0%, rgba(8, 16, 32, .88) 70%);
            z-index: 1;
        }

        .wrap {
            position: relative;
            z-index: 2;
            min-height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .card {
            width: 100%;
            max-width: 520px;
            background: rgba(8, 16, 32, .82);
            border: 1px solid rgba(255, 255, 255, .10);
            border-radius: 24px;
            padding: 48px 44px;
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            box-shadow: 0 24px 60px rgba(0, 0, 0, .45);
        }

        .logo {
            height: 34px;
            width: auto;
            margin-bottom: 40px;
        }

        .eyebrow {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 13px;
            font-weight: 500;
            color: var(--green);
            margin: 0 0 10px;
        }

        .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 0 0 rgba(198, 255, 0, .55);
            animation: pulse 2.4s ease-out infinite;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(198, 255, 0, .55);
            }

            70% {
                box-shadow: 0 0 0 11px rgba(198, 255, 0, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(198, 255, 0, 0);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .dot {
                animation: none;
            }
        }

        h1 {
            font-size: 30px;
            line-height: 1.25;
            font-weight: 700;
            margin: 0 0 16px;
            letter-spacing: -.015em;
        }

        h1 span {
            background: linear-gradient(90deg, var(--blue), var(--green));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        p.lead {
            font-size: 14.5px;
            line-height: 1.65;
            color: rgba(255, 255, 255, .58);
            margin: 0;
        }

        .note {
            margin-top: 24px;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid rgba(37, 99, 255, .28);
            background: rgba(37, 99, 255, .12);
            font-size: 14px;
            line-height: 1.6;
            color: rgba(255, 255, 255, .86);
        }

        .until {
            margin-top: 24px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, .12);
            background: rgba(255, 255, 255, .05);
            font-size: 13.5px;
            font-weight: 500;
        }

        .until svg {
            width: 15px;
            height: 15px;
            stroke: var(--green);
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .foot {
            margin-top: 40px;
            padding-top: 22px;
            border-top: 1px solid rgba(255, 255, 255, .07);
            font-size: 12.5px;
            line-height: 1.6;
            color: rgba(255, 255, 255, .45);
        }

        @media (max-width: 640px) {
            .card {
                padding: 36px 26px;
                border-radius: 20px;
            }

            h1 {
                font-size: 25px;
            }

            .logo {
                margin-bottom: 30px;
            }
        }
    </style>
</head>

<body>
    <picture>
        <source media="(max-width: 640px)" srcset="/img/bg-mobile.png">
        <img src="/img/bg.png" alt="" class="bg">
    </picture>
    <div class="veil"></div>

    <div class="wrap">
        <main class="card">
            <img src="/img/logo-neg.svg" alt="Lavoro" class="logo">

            <p class="eyebrow"><span class="dot"></span> Onderhoud</p>

            <h1>Even niet <span>beschikbaar</span></h1>

            <p class="lead">
                We werken op dit moment aan Lavoro. Je hoeft niets te doen — zodra we
                klaar zijn ben je er automatisch weer in.
            </p>

            @if (!empty($maintenance_message))
                <div class="note">{{ $maintenance_message }}</div>
            @endif

            @if (!empty($maintenance_until))
                <div class="until">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 7v5l3 2" />
                    </svg>
                    Verwacht weer online: {{ $maintenance_until }}
                </div>
            @endif

            <p class="foot">
                Deze pagina ververst zichzelf. Duurt het langer dan verwacht, neem dan
                contact op met je beheerder.
            </p>

            <noscript>
                <p class="foot">
                    Ververs deze pagina zelf om te zien of Lavoro er weer is.
                </p>
            </noscript>
        </main>
    </div>

    <script>
        setInterval(function () {
            if (document.hidden) {
                return;
            }

            fetch(window.location.pathname, { method: 'HEAD', cache: 'no-store' })
                .then(function (response) {
                    if (response.status !== 503) {
                        window.location.reload();
                    }
                })
                .catch(function () {});
        }, 15000);
    </script>
</body>

</html>

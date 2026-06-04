{{-- HTML version to avoid markdown auto-formatting issues --}}
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Afspraakbevestiging #{{ $serviceOrder->id }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, 'Noto Sans', sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji';
            margin: 0;
            padding: 0;
            background: #f4f6f8;
        }

        .wrapper {
            width: 100%;
            padding: 24px 0;
        }

        .container {
            max-width: 570px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .05);
            padding: 24px;
        }

        h1 {
            font-size: 18px;
            margin: 0 0 20px;
            color: #1a202c;
        }

        p {
            line-height: 1.55;
            font-size: 14px;
            color: #374151;
            margin: 0 0 16px;
        }

        .meta strong {
            color: #2d3748;
        }

        .footer {
            text-align: center;
            font-size: 12px;
            color: #a0aec0;
            margin-top: 28px;
            padding: 24px 0 0;
        }

        .logo {
            text-align: center;
            margin-bottom: 24px;
        }

        .logo img {
            max-height: 56px;
        }

        .label {
            font-weight: 600;
        }

        .desc {
            white-space: pre-line;
        }

        .tasks {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            margin: 0 0 16px;
        }

        .tasks th {
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
            padding: 4px 8px 4px 0;
        }

        .tasks td {
            padding: 4px 8px 4px 0;
            color: #374151;
            vertical-align: top;
        }

        .tasks td.done {
            color: #9ca3af;
            text-decoration: line-through;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="logo">
            @if ($company?->logo_path)
                <img src="{{ asset('storage/' . $company->logo_path) }}" alt="{{ $company->name }}">
            @elseif ($company?->name)
                <span style="font-size:20px;font-weight:600;color:#2d3748;">{{ $company->name }}</span>
            @endif
        </div>
        <div class="container">
            <h1>Afspraakbevestiging</h1>
            <p>Beste {{ $serviceOrder->customer->contact_person ?? $serviceOrder->customer->name }},</p>
            <p>Hierbij bevestigen wij uw afspraak:</p>
            <p class="meta">
                <span class="label">Datum:</span> {{ $event->start->format('d-m-Y') }}<br>
                <span class="label">Tijd:</span> {{ $event->start->format('H:i') }} – {{ $event->end->format('H:i') }}<br>
                <span class="label">Werkbon:</span> #{{ $serviceOrder->id }}<br>
                <span class="label">Klant:</span> {{ $serviceOrder->customer->name }}
            </p>
            @if ($serviceOrder->description)
                <p>
                    <span class="label">Omschrijving:</span><br>
                    <span class="desc">{{ $serviceOrder->description }}</span>
                </p>
            @endif
            @if ($serviceOrder->taskInstances->isNotEmpty())
                <p><span class="label">Werkzaamheden:</span></p>
                <table class="tasks">
                    <thead>
                        <tr>
                            <th>Omschrijving</th>
                            <th>Afgerond</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($serviceOrder->taskInstances as $task)
                            @php($task_title = $task->title ?: ($task->serviceOrderTask?->title ?? '—'))
                            <tr>
                                <td class="{{ $task->is_complete ? 'done' : '' }}">{{ $task_title }}</td>
                                <td>{{ $task->is_complete ? 'Ja' : 'Nee' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <p>Met vriendelijke groet,</p>
            <p>{{ $company?->name ?? config('app.name') }}</p>
        </div>
        @php($company_name = $company?->name ?? config('app.name'))
        <div class="footer">
            &copy; {{ date('Y') }} {{ $company_name }}. Alle rechten voorbehouden.<br>
            <span style="font-size:11px;color:#cbd5e0;">Verzonden via Lavoro FSM</span>
        </div>
    </div>
</body>
</html>

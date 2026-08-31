<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lavoro Beheer</title>
<style>
:root{--line:#e2e8f0;--muted:#64748b;--bg:#f8fafc;--accent:#1d4ed8}
*{box-sizing:border-box}
body{margin:0;font:14px/1.5 system-ui,sans-serif;background:var(--bg);color:#0f172a}
header{background:#0f172a;color:#fff;padding:14px 24px;display:flex;justify-content:space-between;align-items:center}
header a{color:#cbd5e1;text-decoration:none}
main{max-width:1100px;margin:24px auto;padding:0 24px}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start}
@media(max-width:900px){.cols{grid-template-columns:1fr}}
.card h3{margin-top:0}
.flash-bad{background:#fef2f2;border-color:#fecaca;color:#b91c1c}
    .linkish{background:none;border:0;padding:0;font:inherit;color:#2563eb;cursor:pointer;text-decoration:underline}
    .choice{border:1px solid var(--line);border-radius:6px;overflow:hidden}
.choice label{display:flex;align-items:center;gap:10px;margin:0;padding:9px 12px;font-weight:400;cursor:pointer}
.choice label+label{border-top:1px solid var(--line)}
.choice label:has(input:checked){background:#eff6ff}
.choice .choice-label{width:110px;font-weight:600}
.choice input[type=number]{width:110px}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);border-radius:8px;overflow:hidden}
th,td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--line)}
th{background:#f1f5f9;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
tr:last-child td{border-bottom:0}
.card{background:#fff;border:1px solid var(--line);border-radius:8px;padding:20px;max-width:520px}
label{display:block;margin:12px 0 4px;font-weight:600}
input[type=text],input[type=email],input[type=password],input[type=number],select{width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:6px;font:inherit}
button{background:var(--accent);color:#fff;border:0;padding:9px 16px;border-radius:6px;font:inherit;cursor:pointer}
.muted{color:var(--muted)}
.warn{color:#b91c1c;font-weight:600}
.flash{background:#dcfce7;border:1px solid #86efac;padding:10px 14px;border-radius:6px;margin-bottom:16px}
.err{background:#fee2e2;border:1px solid #fca5a5;padding:10px 14px;border-radius:6px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
</style>
</head>
<body>
<header>
    <strong>Lavoro Beheer</strong>
    @auth('landlord')
        <span><a href="{{ route('landlord.index') }}">Tenants</a> &nbsp; <a href="{{ route('landlord.catalogue') }}">Catalogus</a> &nbsp; <a href="{{ route('landlord.resellers') }}">Resellers</a> &nbsp; <a href="{{ route('landlord.collections') }}">Incasso</a> &nbsp;
        <form method="post" action="{{ route('landlord.logout') }}" style="display:inline">@csrf
        <button style="background:transparent;color:#cbd5e1;padding:0">Uitloggen</button></form></span>
    @endauth
</header>
<main>
    @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="flash flash-bad">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="err">{{ $errors->first() }}</div>@endif
    @yield('content')
</main>
</body>
</html>

@extends('landlord.layout')
@section('content')
<div class="card" style="max-width:100%">
    <h3 style="margin-top:0">Nieuwe reseller</h3>
    <form method="post" action="{{ route('landlord.reseller.store') }}">@csrf
        <div class="grid">
            <div><label>Naam</label><input type="text" name="name" required></div>
            <div><label>E-mailadres</label><input type="email" name="email"></div>
        </div>
        <label>Commissie (%)</label>
        <input type="number" name="commission_percent" min="0" max="100" value="10" style="width:120px">
        <p><button type="submit">Toevoegen</button></p>
    </form>
</div>

@foreach($rows as $row)
    <div class="card" style="max-width:100%;margin-top:20px">
        <h3 style="margin-top:0">{{ $row['reseller']->name }}
            <span class="muted" style="font-weight:400">
                {{ $row['reseller']->email }} &middot; {{ $row['reseller']->commission_percent }}% commissie
            </span>
        </h3>
        <p>
            {{ $row['tenants']->count() }} klant(en) &middot;
            <strong>@euro($row['commission']) commissie per maand</strong>
        </p>

        <table>
            <tr><th>Code</th><th>Korting</th><th>Looptijd</th><th>Gebruikt door</th></tr>
            @forelse($row['coupons'] as $coupon)
                <tr>
                    <td><code>{{ $coupon->code }}</code></td>
                    <td>{{ $coupon->discount_percent }}%</td>
                    <td>{{ $coupon->discount_months }} maanden</td>
                    <td>
                        @if($coupon->redeemed_by_tenant_id)
                            <span class="muted">{{ optional($row['tenants']->firstWhere('id', $coupon->redeemed_by_tenant_id))->name ?? $coupon->redeemed_by_tenant_id }}
                            op {{ \Carbon\Carbon::parse($coupon->redeemed_at)->format('d-m-Y') }}</span>
                        @else
                            <strong style="color:#15803d">vrij</strong>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Nog geen coupons.</td></tr>
            @endforelse
        </table>

        <form method="post" action="{{ route('landlord.coupon.store') }}" style="margin-top:14px">@csrf
            <input type="hidden" name="reseller_id" value="{{ $row['reseller']->id }}">
            <div class="grid">
                <div><label>Code <span class="muted">(leeg = willekeurig)</span></label><input type="text" name="code" placeholder="ZOMER2026"></div>
                <div><label>Aantal</label><input type="number" name="aantal" value="1" min="1" max="50"></div>
                <div><label>Korting (%)</label><input type="number" name="discount_percent" value="10" min="1" max="100"></div>
                <div><label>Looptijd (maanden)</label><input type="number" name="discount_months" value="12" min="1" max="60"></div>
            </div>
            <p><button type="submit">Coupons aanmaken</button></p>
        </form>
    </div>
@endforeach
@endsection

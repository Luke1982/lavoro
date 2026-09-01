<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\RedeemCouponRequest;
use App\Http\Requests\Landlord\StoreCouponRequest;
use App\Http\Requests\Landlord\StoreResellerRequest;
use App\Models\Central\Coupon;
use App\Models\Central\Reseller;
use App\Models\Tenant;
use App\Services\CouponRedeemer;
use App\Services\TenantSubscription;
use Illuminate\Support\Str;

/**
 * Resellers en hun kortingsbonnen.
 */
class ResellerController extends Controller
{
    public function resellers()
    {
        $resellers = Reseller::on('central')->orderBy('name')->get()->map(function ($reseller) {
            $tenants = Tenant::on('central')->where('reseller_id', $reseller->id)->get();

            return [
                'reseller' => $reseller,
                'coupons' => Coupon::on('central')->where('reseller_id', $reseller->id)->latest()->get(),
                'tenants' => $tenants,
                'commission' => $tenants->sum(fn ($t) => (new TenantSubscription($t))->commissionCents()),
            ];
        });

        return view('landlord.resellers', ['rows' => $resellers]);
    }

    public function storeReseller(StoreResellerRequest $request)
    {
        Reseller::on('central')->create($request->validated());

        return back()->with('status', 'Reseller toegevoegd.');
    }

    public function storeCoupon(StoreCouponRequest $request)
    {
        $data = $request->validated();

        for ($i = 0; $i < $data['aantal']; $i++) {
            Coupon::on('central')->create([
                'code' => strtoupper($data['code'] ?: Str::random(4) . '-' . Str::random(4)),
                'reseller_id' => $data['reseller_id'],
                'discount_percent' => $data['discount_percent'],
                'discount_months' => $data['discount_months'],
            ]);
        }

        return back()->with('status', $data['aantal'] . ' coupon(s) aangemaakt.');
    }

    public function redeemCoupon(RedeemCouponRequest $request, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        try {
            $coupon = app(CouponRedeemer::class)
                ->redeem(strtoupper(trim($request->input('code'))), $tenant);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->with('status', "Coupon {$coupon->code} verzilverd.");
    }
}

<?php

namespace App\Services;

use App\Exceptions\Refusal;
use App\Models\Central\Coupon;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class CouponRedeemer
{
    /**
     * Verzilvert een bon voor een tenant.
     *
     * De update zet zelf de voorwaarde dat de bon nog vrij is, zodat twee
     * klanten die tegelijk dezelfde code invoeren niet allebei slagen: de
     * tweede raakt nul rijen en krijgt de foutmelding.
     */
    public function redeem(string $code, Tenant $tenant): Coupon
    {
        $coupon = Coupon::on('central')->where('code', $code)->first();

        if (!$coupon) {
            throw new Refusal('Onbekende couponcode.');
        }

        if ($coupon->redeemed_by_tenant_id) {
            throw new Refusal('Deze coupon is al gebruikt.');
        }

        $claimed = DB::connection('central')->table('coupons')
            ->where('id', $coupon->id)
            ->whereNull('redeemed_by_tenant_id')
            ->update(['redeemed_by_tenant_id' => $tenant->id, 'redeemed_at' => now()]);

        if (!$claimed) {
            throw new Refusal('Deze coupon is al gebruikt.');
        }

        $tenant->update([
            'coupon_discount_percent' => $coupon->discount_percent,
            'coupon_discount_until' => now()->addMonths($coupon->discount_months)->toDateString(),
            'reseller_id' => $coupon->reseller_id,
        ]);

        return $coupon->refresh();
    }
}

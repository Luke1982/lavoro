<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\AddTopupRequest;
use App\Http\Requests\Landlord\ForgetProvisioningPasswordRequest;
use App\Models\Central\AiTopup;
use App\Models\Central\PendingCharge;
use App\Models\Central\PricingSetting;
use App\Models\Central\TenantProvisioningRequest;
use App\Models\Tenant;

/**
 * Eenmalig bijgekocht AI-tegoed.
 */
class TopupController extends Controller
{
    public function addTopup(AddTopupRequest $request, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $data = $request->validated();

        $rate = PricingSetting::value('ai_topup_cents_per_euro_granted', 200);
        $paid_cents = (int) round((float) $data['paid_euro'] * 100);

        AiTopup::on('central')->create([
            'tenant_id' => $tenant->id,
            'paid_cents' => $paid_cents,
            'granted_micros' => (int) round($paid_cents / max(1, $rate) * 1_000_000),
            'note' => $data['note'] ?? null,
        ]);

        /** The credit is usable straight away; the money goes on the next invoice. */
        PendingCharge::on('central')->create([
            'tenant_id' => $tenant->id,
            'description' => 'Extra AI-tegoed' . (($data['note'] ?? null) ? ' (' . $data['note'] . ')' : ''),
            'kind' => 'topup',
            'amount_cents' => $paid_cents,
        ]);

        return back()->with('status', 'Bijkoop toegevoegd.');
    }

    /** A new tenant's password: shown once, then gone. */
    public function forgetProvisioningPassword(ForgetProvisioningPasswordRequest $request, int $id)
    {
        TenantProvisioningRequest::on('central')
            ->where('id', $id)->update(['generated_password' => null]);

        return back()->with('status', 'Wachtwoord gewist.');
    }
}

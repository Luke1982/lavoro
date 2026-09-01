<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\ForgetProvisioningPasswordRequest;
use App\Models\Central\AiTopup;
use App\Models\Central\PendingCharge;
use App\Models\Central\PricingSetting;
use App\Models\Central\TenantProvisioningRequest;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Eenmalig bijgekocht AI-tegoed.
 */
class TopupController extends Controller
{
    public function addTopup(Request $request, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $data = $request->validate(['paid_euro' => 'required|numeric|min:0.01', 'note' => 'nullable|string']);

        $rate = PricingSetting::value('ai_topup_cents_per_euro_granted', 200);
        $paid_cents = (int) round((float) $data['paid_euro'] * 100);

        AiTopup::on('central')->create([
            'tenant_id' => $tenant->id,
            'paid_cents' => $paid_cents,
            'granted_micros' => (int) round($paid_cents / max(1, $rate) * 1_000_000),
            'note' => $data['note'] ?? null,
        ]);

        /** Het tegoed is meteen bruikbaar; het geld gaat op de eerstvolgende factuur. */
        PendingCharge::on('central')->create([
            'tenant_id' => $tenant->id,
            'description' => 'Extra AI-tegoed' . (($data['note'] ?? null) ? ' (' . $data['note'] . ')' : ''),
            'kind' => 'topup',
            'amount_cents' => $paid_cents,
        ]);

        return back()->with('status', 'Bijkoop toegevoegd.');
    }

    /** Het wachtwoord van een nieuwe tenant: één keer tonen, dan weg. */
    public function forgetProvisioningPassword(ForgetProvisioningPasswordRequest $request, int $id)
    {
        TenantProvisioningRequest::on('central')
            ->where('id', $id)->update(['generated_password' => null]);

        return back()->with('status', 'Wachtwoord gewist.');
    }
}

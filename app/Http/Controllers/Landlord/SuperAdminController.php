<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\DestroySuperAdminRequest;
use App\Http\Requests\Landlord\StoreSuperAdminRequest;
use App\Models\Tenant;
use App\Services\TenantSuperAdmins;

/**
 * Onze eigen accounts binnen de database van een klant.
 */
class SuperAdminController extends Controller
{
    public function storeSuperAdmin(StoreSuperAdminRequest $request, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);
        $data = $request->validated();

        /** Een weigering met een reden wordt centraal afgehandeld; zie bootstrap/app.php. */
        $password = app(TenantSuperAdmins::class)
            ->create($tenant, $data['email'], $data['password'] ?? '');

        return back()->with('status', "Superbeheerder {$data['email']} aangemaakt. Wachtwoord: {$password}");
    }

    public function destroySuperAdmin(DestroySuperAdminRequest $request, string $id, int $user)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        app(TenantSuperAdmins::class)->remove($tenant, $user);

        return back()->with('status', 'Superbeheerder verwijderd.');
    }
}

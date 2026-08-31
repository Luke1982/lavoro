<?php

namespace App\Jobs;

use App\Models\Central\TenantProvisioningRequest;
use App\Models\Tenant;
use App\Services\TenantProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Voert één aanvraag uit het beheerpaneel uit.
 *
 * Draait op de aparte wachtrij 'provisioning', want alleen de worker die als
 * lavoro_provisioner draait mag databases aanmaken en weggooien. De gewone
 * worker draait als lavoro_app en zou hier stuklopen -- daarom een eigen
 * wachtrij en niet de standaard.
 */
class RunTenantProvisioningRequestJob implements ShouldQueue
{
    use Queueable;

    /**
     * Eén poging. Een halve tenant nog eens proberen aan te maken loopt vast op
     * "de database bestaat al" en verbergt de echte fout; opruimen en opnieuw
     * indienen is de juiste weg.
     */
    public $tries = 1;

    public function __construct(public int $request_id) {}

    public function handle(TenantProvisioner $provisioner): void
    {
        $request = TenantProvisioningRequest::on('central')->find($this->request_id);

        if (!$request || !$request->isOpen()) {
            return;
        }

        $request->update(['status' => 'running']);

        try {
            $request->action === 'delete'
                ? $this->delete($provisioner, $request)
                : $this->create($provisioner, $request);

            $request->update(['status' => 'done', 'error' => null, 'finished_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('Aanvraag mislukt', ['request' => $request->id, 'error' => $e->getMessage()]);

            $request->update([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now(),
            ]);
        }
    }

    private function create(TenantProvisioner $provisioner, TenantProvisioningRequest $request): void
    {
        ['tenant' => $tenant, 'password' => $password] = $provisioner->create(
            name: (string) $request->name,
            email: (string) $request->email,
            package: (string) ($request->package_key ?: 'starter'),
            modules: $request->modules ?? [],
        );

        /**
         * Het wachtwoord wordt één keer getoond en daarna gewist. Het staat hier
         * omdat de aanvrager het scherm al lang verlaten kan hebben als de
         * worker klaar is, en anders is er geen manier om erin te komen.
         */
        $request->update(['tenant_id' => $tenant->id, 'generated_password' => $password]);
    }

    private function delete(TenantProvisioner $provisioner, TenantProvisioningRequest $request): void
    {
        $tenant = Tenant::on('central')->find($request->tenant_id);

        if (!$tenant) {
            throw new RuntimeException('Onbekende tenant; mogelijk al verwijderd.');
        }

        $provisioner->destroy($tenant);
    }

    public function failed(\Throwable $e): void
    {
        TenantProvisioningRequest::on('central')->where('id', $this->request_id)->update([
            'status' => 'failed',
            'error' => mb_substr($e->getMessage(), 0, 2000),
            'finished_at' => now(),
        ]);
    }
}

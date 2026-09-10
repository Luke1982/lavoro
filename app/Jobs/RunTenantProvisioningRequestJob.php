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
 * Carries out one request from the admin panel.
 *
 * Runs on the separate 'provisioning' queue, because only the worker running as
 * lavoro_provisioner may create and drop databases. The ordinary worker runs as
 * lavoro_app and would break here -- hence a queue of its own and not the
 * default.
 */
class RunTenantProvisioningRequestJob implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt. Trying to create half a tenant again gets stuck on "the
     * database already exists" and hides the real error; cleaning up and
     * submitting again is the right way.
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
         * The password is shown once and wiped after. It is here because the
         * requester may long have left the screen by the time the worker is
         * done, and then there is no way of getting in.
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

        /**
         * The password of a customer that no longer exists should not be
         * anywhere any more, and certainly not visible in the admin panel.
         */
        TenantProvisioningRequest::on('central')
            ->where('tenant_id', $tenant->id)
            ->update(['generated_password' => null]);
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

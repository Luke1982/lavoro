<?php

namespace App\Tenancy;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper as Stancl;

/**
 * Stancl's, and a job whose customer no longer exists is thrown away instead
 * of failing.
 *
 * A job carries its tenant in the payload, and the worker looks that tenant up
 * when the job comes round. Delete the customer in between -- the demo is
 * rebuilt every night -- and the lookup finds nothing, so the job fails with a
 * message naming no tenant at all: what it could not find is what it looked
 * for. Retrying can never help, because the customer is gone.
 *
 * TenantProvisioner::destroy() clears what is still waiting, but between
 * queueing and deleting sits the worker: a job it already had in hand is past
 * that point. On production that was four jobs on two nights, all at 04:00,
 * when the demo is rebuilt.
 */
class QueueTenancyBootstrapper extends Stancl
{
    public static function __constructStatic(Application $app)
    {
        static::discardWorkOfVanishedTenants($app->make(Dispatcher::class));

        parent::__constructStatic($app);
    }

    /**
     * Laravel checks whether a job was deleted right after this event and then
     * skips it, so this is the one place to stop it without a failure.
     */
    private static function discardWorkOfVanishedTenants(Dispatcher $dispatcher): void
    {
        $dispatcher->listen(JobProcessing::class, function (JobProcessing $event) {
            $tenant_id = $event->job->payload()['tenant_id'] ?? null;

            if (!$tenant_id || tenancy()->find($tenant_id)) {
                return;
            }

            Log::info('Job thrown away: the customer it belonged to is gone', [
                'job' => $event->job->resolveName(),
                'tenant' => $tenant_id,
            ]);

            $event->job->delete();
        });
    }

    /**
     * Quiet when the customer is gone: the listener above already threw the job
     * away, and the parent would throw over a tenant nobody can bring back.
     */
    protected static function initializeTenancyForQueue($tenantId)
    {
        if ($tenantId && !tenancy()->find($tenantId)) {
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            return;
        }

        parent::initializeTenancyForQueue($tenantId);
    }
}

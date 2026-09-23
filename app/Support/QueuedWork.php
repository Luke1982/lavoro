<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * The work waiting in the queue for one customer.
 *
 * A queued job carries its tenant in the payload, and the worker looks that
 * tenant up when the job comes round. Delete the customer in between and the
 * job fails -- with a message naming no tenant at all, because what it could
 * not find is what it looked for.
 *
 * The demo is thrown away and rebuilt every night, so whatever was queued for
 * it in the minutes before produced failed jobs every morning, with anything
 * that really went wrong lost among them. TenantProvisioner::destroy() clears
 * them with the customer.
 */
final class QueuedWork
{
    /** Waiting and failed both: a failed one can never succeed now either. */
    public static function forget(Tenant $tenant): int
    {
        $gone = 0;

        foreach (['jobs', 'failed_jobs'] as $table) {
            $gone += DB::connection('central')->table($table)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.tenant_id')) = ?", [$tenant->getTenantKey()])
                ->delete();
        }

        return $gone;
    }
}

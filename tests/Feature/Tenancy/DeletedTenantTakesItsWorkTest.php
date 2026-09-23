<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Support\QueuedWork;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A queued job carries its tenant in the payload, and the worker looks that
 * tenant up when the job comes round. Delete the customer in between and the
 * job fails -- naming no tenant at all, because what it could not find is what
 * it looked for.
 *
 * The demo is thrown away and rebuilt every night, so the jobs queued for it in
 * the minutes before produced a handful of failed jobs every morning, with
 * anything that really went wrong lost among them. TenantProvisioner::destroy()
 * calls this before it switches to the provisioner; that switch purges the
 * connection, which a test cannot follow.
 */
class DeletedTenantTakesItsWorkTest extends TestCase
{
    /**
     * Written directly: Tenant::create() starts the pipeline that builds a real
     * database, and nothing here needs one.
     */
    private function tenant(): Tenant
    {
        $id = 'weg-' . uniqid();

        DB::connection('central')->table('tenants')->insert([
            'id' => $id,
            'name' => 'Weggaande klant',
            'package_key' => 'business',
            'modules' => json_encode([]),
            'data' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Tenant::on('central')->findOrFail($id);
    }

    private function queue(string $table, string $tenant_id): void
    {
        $payload = json_encode([
            'displayName' => 'App\Jobs\Google\DispatchTenantCalendarPullsJob',
            'tenant_id' => $tenant_id,
        ]);

        DB::connection('central')->table($table)->insert($table === 'jobs'
            ? ['queue' => 'default', 'payload' => $payload, 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]
            : ['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
                'payload' => $payload, 'exception' => 'x', 'failed_at' => now()]);
    }

    private function countFor(string $table, string $tenant_id): int
    {
        return DB::connection('central')->table($table)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.tenant_id')) = ?", [$tenant_id])
            ->count();
    }

    public function test_the_work_waiting_for_a_deleted_tenant_goes_with_it(): void
    {
        $leaving = $this->tenant();
        $staying = (string) tenancy()->tenant->getTenantKey();

        foreach (['jobs', 'failed_jobs'] as $table) {
            $this->queue($table, $leaving->id);
            $this->queue($table, $staying);
        }

        $this->assertSame(2, QueuedWork::forget($leaving), 'two rows belonged to the customer that is leaving');

        foreach (['jobs', 'failed_jobs'] as $table) {
            $this->assertSame(0, $this->countFor($table, $leaving->id), "{$table} still holds work for a customer that is gone");
            $this->assertSame(1, $this->countFor($table, $staying), "{$table} lost the work of another customer");
        }
    }
}

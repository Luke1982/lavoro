<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesASecondTenant;
use Tests\TestCase;

/**
 * A customer whose database is gone -- half created, half cleaned up -- must
 * not take the installation down with it.
 *
 * That happened: the session pointed at that customer, tenancy switched over
 * happily, and the first question to the database broke. The result: a 500 on
 * every page, the login screen included, so there was no getting out of it
 * either.
 */
class BrokenTenantTest extends TestCase
{
    use UsesASecondTenant;

    public function test_a_tenant_without_a_database_does_not_take_the_site_down(): void
    {
        $tenant = $this->secondTenant();
        $database = $tenant->getInternal('db_name');

        /** First prove it works normally, otherwise the rest says nothing. */
        $this->withSession(['tenant_id' => $tenant->getTenantKey()])
            ->get('/login')
            ->assertOk();

        DB::connection('central')->statement("DROP DATABASE IF EXISTS `{$database}`");

        try {
            $this->withSession(['tenant_id' => $tenant->getTenantKey()])
                ->get('/login')
                ->assertOk();
        } finally {
            /** Rebuild it, otherwise the next test runs against nothing. */
            DB::connection('central')->statement("CREATE DATABASE IF NOT EXISTS `{$database}`");
            static::$second_tenant_prepared = false;
        }
    }

    /**
     * The case from production: a logged in user is still in the session of a
     * customer whose database is gone.
     *
     * Auth::forgetUser() only forgets the fetched object; the id is still in
     * the session, so the guard fetches it again -- and then looks for the
     * users table in the central database, where it is not. A 500 on every
     * page, the login screen included.
     */
    public function test_a_logged_in_session_of_a_vanished_tenant_does_not_break_the_site(): void
    {
        $tenant = $this->secondTenant();
        $database = $tenant->getInternal('db_name');

        DB::connection('central')->statement("DROP DATABASE IF EXISTS `{$database}`");

        /**
         * The suite runs with a tenant by default. That has to go here,
         * otherwise the request never passes the code this test is about and it
         * passes for the wrong reason -- which is exactly what happened.
         */
        $guard_key = Auth::guard('web')->getName();
        tenancy()->end();

        try {
            $this->withSession([
                'tenant_id' => $tenant->getTenantKey(),
                $guard_key => 1,
            ])->get('/')->assertRedirect(route('login'));
        } finally {
            DB::connection('central')->statement("CREATE DATABASE IF NOT EXISTS `{$database}`");
            static::$second_tenant_prepared = false;
        }
    }

    public function test_the_session_of_a_vanished_tenant_is_forgotten(): void
    {
        $this->withSession(['tenant_id' => 'bestaat-niet'])
            ->get('/login')
            ->assertOk();

        $this->assertNull(Tenant::on('central')->find('bestaat-niet'));
    }
}

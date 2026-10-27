<?php

namespace Tests\Feature\Landlord;

use App\Models\Central\TenantSuperAdmin;
use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantSuperAdmins;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MajorLabel's own accounts belong to us, not to the customer, so they are kept
 * in the landlord database.
 *
 * They used to live only in the customer's own database. Importing that
 * customer's old installation again replaces that database, and with it every
 * super admin was gone: the panel showed none, and whoever ran the import had
 * locked themselves out of the customer they were working on.
 */
class SuperAdminsSurviveAReimportTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::on('central')->findOrFail('test-tenant');
    }

    private function superAdmins(): TenantSuperAdmins
    {
        return app(TenantSuperAdmins::class);
    }

    /** What an import does to the customer's database, as far as users go. */
    private function replaceTheCustomerDatabase(): void
    {
        DB::connection('tenant')->table('users')->delete();
        DB::connection('central')->table('user_tenant_lookups')
            ->where('tenant_id', $this->tenant->id)->delete();
    }

    public function test_creating_one_writes_it_in_the_landlord_database(): void
    {
        $this->superAdmins()->create($this->tenant, 'beheer@majorlabel.nl');

        $this->assertDatabaseHas('tenant_super_admins', [
            'tenant_id' => $this->tenant->id,
            'email' => 'beheer@majorlabel.nl',
        ], 'central');

        $user = User::withoutGlobalScopes()->where('email', 'beheer@majorlabel.nl')->first();

        $this->assertNotNull($user, 'and a copy of it inside the customer');
        $this->assertTrue($user->isSuperAdmin());
    }

    public function test_the_account_comes_back_after_the_database_is_replaced(): void
    {
        $password = $this->superAdmins()->create($this->tenant, 'beheer@majorlabel.nl');

        $this->replaceTheCustomerDatabase();

        $this->assertNull(User::withoutGlobalScopes()->where('email', 'beheer@majorlabel.nl')->first(),
            'the import really did take it away');

        $this->assertSame(1, $this->superAdmins()->sync($this->tenant));

        $user = User::withoutGlobalScopes()->where('email', 'beheer@majorlabel.nl')->first();

        $this->assertNotNull($user, 'and the import writes it back');
        $this->assertTrue($user->isSuperAdmin());

        $this->assertSame($this->tenant->id,
            UserTenantLookup::on('central')->find('beheer@majorlabel.nl')?->tenant_id,
            'without the central row, logging in cannot find the customer');

        $this->assertTrue(password_verify($password, $user->password),
            'with the password that was handed out, not a new one');
    }

    public function test_the_panel_lists_them_even_when_the_customer_database_is_gone(): void
    {
        $this->superAdmins()->create($this->tenant, 'beheer@majorlabel.nl');

        $this->replaceTheCustomerDatabase();

        $listed = $this->superAdmins()->all($this->tenant);

        $this->assertCount(1, $listed, 'read from the landlord database, so it is still known');
        $this->assertSame('beheer@majorlabel.nl', $listed[0]['email']);
    }

    public function test_removing_one_takes_both_copies(): void
    {
        $this->superAdmins()->create($this->tenant, 'beheer@majorlabel.nl');

        $listed = $this->superAdmins()->all($this->tenant);
        $this->superAdmins()->remove($this->tenant, $listed[0]['id']);

        $this->assertSame([], $this->superAdmins()->all($this->tenant));
        $this->assertNull(User::withoutGlobalScopes()->where('email', 'beheer@majorlabel.nl')->first());
        $this->assertNull(UserTenantLookup::on('central')->find('beheer@majorlabel.nl'));
    }

    /** One address belongs to one customer, here too. */
    public function test_an_address_already_used_at_another_customer_is_refused(): void
    {
        TenantSuperAdmin::create([
            'tenant_id' => 'another-tenant',
            'name' => 'MajorLabel',
            'email' => 'beheer@majorlabel.nl',
            'password' => bcrypt('whatever'),
        ]);

        $this->expectExceptionMessageMatches('/al superbeheerder bij een andere tenant/');

        $this->superAdmins()->create($this->tenant, 'beheer@majorlabel.nl');
    }
}

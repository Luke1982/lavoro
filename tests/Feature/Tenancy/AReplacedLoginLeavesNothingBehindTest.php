<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Services\TenantDbUserProvisioner;
use Illuminate\Support\Str;
use Tests\Concerns\OutsideTheTestTransaction;
use Tests\TestCase;

/**
 * Handing a customer a new MySQL login has to take the old one away.
 *
 * The name is random and a new one every round, so dropping only the name that
 * was just made leaves the previous account standing: every right on this
 * customer's database, a password nobody has written down any more, and it
 * keeps working. MySQL holds on to its grants even when the database is
 * dropped, so it is waiting for that name to come back as well.
 *
 * A customer is re-provisioned when an installation that was taken over is
 * imported a second time.
 */
class AReplacedLoginLeavesNothingBehindTest extends TestCase
{
    use OutsideTheTestTransaction;

    private ?string $to_clean_up = null;

    protected function tearDown(): void
    {
        /** CREATE USER is not in the test transaction; this is a real account. */
        if ($this->to_clean_up) {
            $this->outsideTheTransaction()->statement("DROP USER IF EXISTS '{$this->to_clean_up}'@'%'");
        }

        parent::tearDown();
    }

    private function logins(string $name): int
    {
        return (int) $this->outsideTheTransaction()
            ->selectOne('SELECT COUNT(*) AS found FROM mysql.user WHERE user = ?', [$name])->found;
    }

    public function test_the_login_it_had_before_is_gone(): void
    {
        $tenant = Tenant::withoutEvents(fn () => Tenant::on('central')->create([
            'id' => 'opnieuw-' . Str::lower(Str::random(6)),
            'name' => 'Opnieuw BV',
            'tenancy_db_name' => Tenant::on('central')->findOrFail('test-tenant')->getInternal('db_name'),
        ]));

        $provisioner = app(TenantDbUserProvisioner::class);

        $provisioner->provision($tenant);
        $first = (string) $tenant->tenancy_db_username;

        $provisioner->provision($tenant);
        $this->to_clean_up = $second = (string) $tenant->tenancy_db_username;

        $this->assertNotSame($first, $second, 'a new round is a new name');
        $this->assertSame(1, $this->logins($second), 'the customer has a working login');
        $this->assertSame(0, $this->logins($first), 'the one from before may not stay behind');

        $this->assertSame(0, (int) $this->outsideTheTransaction()
            ->selectOne('SELECT COUNT(*) AS found FROM mysql.db WHERE user = ?', [$first])->found,
            'and neither may its rights, waiting for the name to be handed out again');
    }
}

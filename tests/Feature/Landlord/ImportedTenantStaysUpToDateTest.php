<?php

namespace Tests\Feature\Landlord;

use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use App\Services\TenantDbUserProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\Concerns\KeepsTheTestTenantIntact;
use Tests\TestCase;

/**
 * An installation that was taken over is fetched again later, to pick up what
 * the customer did there in the meantime. That second run has to land on the
 * customer that is already here.
 *
 * It used to refuse, and the way around it was a second customer on the same
 * database: two rows, two subscriptions, and an invoice from each of them.
 */
class ImportedTenantStaysUpToDateTest extends TestCase
{
    use KeepsTheTestTenantIntact;

    private string $database;

    /** @var array<int, string> */
    private array $at_the_source = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberTheTestTenant();

        $this->database = Tenant::on('central')->findOrFail('test-tenant')->getInternal('db_name');

        /** The MySQL login belongs to provisioning; this is about the customer. */
        $this->app->instance(TenantDbUserProvisioner::class, Mockery::mock(TenantDbUserProvisioner::class)
            ->shouldReceive('provision')->andReturnNull()->getMock());

        /**
         * The command switches its connections to the provisioner, which on a
         * server is a socket only that account may use.
         */
        config(['database.connections.provisioner' => config('database.connections.central')]);

        /** The addresses in the adopted database belong to the test tenant here. */
        $this->outsideTheTransaction()->table('user_tenant_lookups')->delete();

        /** So the first run below is a first import; see the other test. */
        $this->parkTheTestTenantRegistration();

        $this->at_the_source = $this->peopleAtTheSource(3);
    }

    /**
     * People in the database that is being taken over.
     *
     * Over a connection of their own, and that is the whole trick: the suite
     * runs in a transaction, the command purges the central connection, and
     * purging drops it -- with everything that was not committed. Written this
     * way they are simply there, like they would be in the installation that is
     * being taken over. tearDown clears them away again.
     *
     * @return array<int, string>
     */
    private function peopleAtTheSource(int $how_many): array
    {
        $emails = [];

        foreach (range(1, $how_many) as $number) {
            $email = 'overgenomen-' . $number . '-' . uniqid() . '@example.nl';

            $this->outsideTheTransaction()->insert(
                "INSERT INTO `{$this->database}`.users (name, email, password, created_at, updated_at)
                 VALUES (?, ?, ?, NOW(), NOW())",
                ['Monteur ' . $number, $email, 'x'],
            );

            $emails[] = $email;
        }

        return $emails;
    }

    protected function tearDown(): void
    {
        /** Committed like everything else the command touches; see the concern. */
        $this->outsideTheTransaction()->delete(
            "DELETE FROM `{$this->database}`.users WHERE email LIKE 'overgenomen-%'"
        );

        $this->putTheTestTenantBack();

        parent::tearDown();
    }

    private function import(string $name, array $options = []): int
    {
        return Artisan::call('tenant:setup-existing', [
            'name' => $name,
            'database' => $this->database,
            ...$options,
        ]);
    }

    private function imported(): Tenant
    {
        return Tenant::on('central')->where('data->tenancy_db_name', $this->database)->sole();
    }

    public function test_importing_the_same_installation_again_keeps_one_customer(): void
    {
        $this->import('Overgenomen BV');
        $first = $this->imported();

        $this->assertSame(Command::SUCCESS, $this->import('Overgenomen BV'), 'a second import is not an error');

        $again = $this->imported();

        $this->assertSame($first->id, $again->id, 'the same customer, not a second one on the same database');
        $this->assertSame(1, Tenant::on('central')->where('data->tenancy_db_name', $this->database)->count());
    }

    /**
     * What the customer is worth is agreed on this side. Only what comes from
     * the other installation may be redone.
     */
    public function test_the_subscription_survives_the_second_import(): void
    {
        $this->import('Overgenomen BV', ['--started-on' => '2025-03-01']);

        $tenant = $this->imported();
        $tenant->package_key = 'enterprise';
        $tenant->extra_field_seats = 7;
        $tenant->modules = ['quotes'];
        $tenant->save();

        $this->import('Overgenomen BV');

        $tenant = $this->imported();

        $this->assertSame('enterprise', $tenant->package_key);
        $this->assertSame(7, (int) $tenant->extra_field_seats);
        $this->assertSame(['quotes'], $tenant->modules);
        $this->assertSame('2025-03-01', (string) $tenant->subscription_started_on,
            'the day billing started is not moved to today by a re-import');
    }

    /** A name corrected at the source, or a takeover renamed here, follows. */
    public function test_the_name_follows_the_second_import(): void
    {
        $this->import('Overgenomen BV');
        $this->import('Overgenomen Totaaltechniek BV');

        $this->assertSame('Overgenomen Totaaltechniek BV', $this->imported()->name);
    }

    /**
     * Who may log in is read from the other installation every time. A
     * colleague who started there has to arrive at the right customer, and one
     * who left may not keep an address here that points at it.
     */
    public function test_the_logins_are_read_again(): void
    {
        $this->import('Overgenomen BV');

        $tenant = $this->imported();

        $this->assertEqualsCanonicalizing($this->at_the_source,
            UserTenantLookup::on('central')->where('tenant_id', $tenant->id)->pluck('email')->all());

        /** One who left, one who is new, and one address that points nowhere. */
        UserTenantLookup::on('central')->where('email', $this->at_the_source[0])->delete();
        $this->at_the_source = array_merge($this->at_the_source, $this->peopleAtTheSource(1));
        UserTenantLookup::on('central')->create([
            'email' => 'vertrokken@overgenomen.nl',
            'tenant_id' => $tenant->id,
        ]);

        $this->import('Overgenomen BV');

        $after = UserTenantLookup::on('central')->where('tenant_id', $tenant->id)->pluck('email');

        $this->assertEqualsCanonicalizing($this->at_the_source, $after->all(),
            'the addresses at the source are the addresses here');
        $this->assertSame($after->count(), $after->unique()->count(), 'nobody is in there twice');
    }

    /**
     * The addresses of the customer itself are not a conflict on a second run
     * -- that check is what refused the re-import in the first place.
     */
    public function test_an_address_at_another_customer_still_refuses(): void
    {
        $this->import('Overgenomen BV');

        $other = Tenant::withoutEvents(fn () => Tenant::on('central')->create([
            'id' => 'other-tenant',
            'name' => 'Andere Klant',
            'tenancy_db_name' => 'lavoro_test_tenant_other',
        ]));

        $taken = UserTenantLookup::on('central')
            ->where('tenant_id', $this->imported()->id)
            ->value('email');

        UserTenantLookup::on('central')->where('email', $taken)->update(['tenant_id' => $other->id]);

        $this->assertSame(Command::FAILURE, $this->import('Overgenomen BV'), 'one address, two customers: that has to stop');
        $this->assertSame($other->id, UserTenantLookup::on('central')->where('email', $taken)->value('tenant_id'),
            'and it changes nothing while it refuses');
    }
}

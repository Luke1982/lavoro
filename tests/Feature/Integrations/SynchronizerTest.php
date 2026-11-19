<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\ChangeDetection;
use App\Domain\Integrations\Connectors;
use App\Domain\Integrations\IntegrationUnavailable;
use App\Domain\Integrations\Sync\Synchronizer;
use App\Exceptions\Refusal;
use App\Jobs\Integrations\PullRecordJob;
use App\Models\Customer;
use App\Models\Integrationable;
use App\Models\IntegrationConnection;
use App\Models\IntegrationRun;
use App\Models\Material;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Fakes\FakeConnector;
use Tests\TestCase;

/**
 * The rules of two-way sync, against a system kept in memory: what moved on
 * one side goes across, a clash is settled by whoever leads, and nothing is
 * sent back that just came in.
 */
class SynchronizerTest extends TestCase
{
    private IntegrationConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        FakeConnector::reset();
        Connectors::extend(FakeConnector::class);

        $this->connection = IntegrationConnection::create([
            'connector' => 'fake',
            'is_active' => true,
            'settings' => [
                'customers' => ['read' => true, 'write' => true, 'leading' => 'external'],
                'materials' => ['read' => true],
                'service_orders' => ['form' => 'sales_order'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Connectors::forgetExtensions();

        parent::tearDown();
    }

    private function sync(): Synchronizer
    {
        return app(Synchronizer::class);
    }

    private function pullCustomers(): IntegrationRun
    {
        return $this->sync()->pull($this->connection->fresh(), Area::Customers);
    }

    /** A change here that does not go out on its own, as when sending was switched off at the time. */
    private function withoutSending(callable $change): void
    {
        $settings = $this->connection->fresh()->settings;
        $this->customers(read: true, write: false, leading: $settings['customers']['leading']);
        $change();
        $this->customers(...$settings['customers']);
    }

    private function customers(bool $read, bool $write, string $leading): void
    {
        $this->connection->update([
            'settings' => [...$this->connection->settings, 'customers' => compact('read', 'write', 'leading')],
        ]);
    }

    public function test_a_pull_brings_in_new_customers_and_links_them(): void
    {
        FakeConnector::$customers['A1'] = ['name' => 'Bakkerij Jansen', 'city' => 'Zwolle'];

        $run = $this->pullCustomers();

        $customer = Customer::where('name', 'Bakkerij Jansen')->firstOrFail();
        $this->assertSame('Zwolle', $customer->city);
        $this->assertSame('A1', $customer->externalId($this->connection));
        $this->assertSame(['created' => 1], $run->counts);
        $this->assertSame([], FakeConnector::$calls, 'what just came in is not sent straight back');
    }

    public function test_a_second_pull_without_changes_does_nothing(): void
    {
        FakeConnector::$customers['A1'] = ['name' => 'Bakkerij Jansen'];
        $this->pullCustomers();

        $run = $this->pullCustomers();

        $this->assertSame(['unchanged' => 1], $run->counts);
    }

    public function test_a_change_only_on_their_side_is_taken(): void
    {
        FakeConnector::$customers['A1'] = ['name' => 'Bakkerij Jansen', 'city' => 'Zwolle'];
        $this->pullCustomers();

        FakeConnector::$customers['A1']['city'] = 'Kampen';
        $run = $this->pullCustomers();

        $this->assertSame('Kampen', Customer::where('name', 'Bakkerij Jansen')->value('city'));
        $this->assertSame(['updated' => 1], $run->counts);
    }

    public function test_a_change_only_on_our_side_is_sent(): void
    {
        FakeConnector::$customers['A1'] = ['name' => 'Bakkerij Jansen', 'city' => 'Zwolle'];
        $this->pullCustomers();

        Customer::where('name', 'Bakkerij Jansen')->firstOrFail()->update(['city' => 'Deventer']);

        $this->assertSame('Deventer', FakeConnector::$customers['A1']['city']);
        $this->assertSame(['update:A1'], FakeConnector::$calls);
    }

    public function test_a_change_on_both_sides_goes_to_them_when_they_lead(): void
    {
        FakeConnector::$customers['A1'] = ['name' => 'Bakkerij Jansen', 'city' => 'Zwolle'];
        $this->pullCustomers();

        $this->customers(read: true, write: false, leading: 'external');
        Customer::where('name', 'Bakkerij Jansen')->firstOrFail()->update(['city' => 'Deventer']);
        FakeConnector::$customers['A1']['city'] = 'Kampen';

        $run = $this->pullCustomers();

        $this->assertSame('Kampen', Customer::where('name', 'Bakkerij Jansen')->value('city'));
        $this->assertSame(['updated' => 1], $run->counts);
    }

    public function test_a_change_on_both_sides_stays_ours_when_lavoro_leads_and_is_sent_to_them(): void
    {
        FakeConnector::$customers['A1'] = ['name' => 'Bakkerij Jansen', 'city' => 'Zwolle'];
        $this->pullCustomers();

        $this->customers(read: true, write: false, leading: 'lavoro');
        Customer::where('name', 'Bakkerij Jansen')->firstOrFail()->update(['city' => 'Deventer']);
        FakeConnector::$customers['A1']['city'] = 'Kampen';
        $this->customers(read: true, write: true, leading: 'lavoro');

        $run = $this->pullCustomers();

        $this->assertSame('Deventer', Customer::where('name', 'Bakkerij Jansen')->value('city'));
        $this->assertSame(['kept_ours' => 1], $run->counts);
        $this->assertSame('Deventer', FakeConnector::$customers['A1']['city'], 'our version went across');
    }

    public function test_sending_a_change_first_takes_what_they_changed_meanwhile(): void
    {
        FakeConnector::$customers['A1'] = ['name' => 'Bakkerij Jansen', 'city' => 'Zwolle'];
        $this->pullCustomers();
        FakeConnector::$customers['A1']['phone'] = '0381234567';

        Customer::where('name', 'Bakkerij Jansen')->firstOrFail()->update(['city' => 'Deventer']);

        $customer = Customer::where('name', 'Bakkerij Jansen')->firstOrFail();
        $this->assertSame('Deventer', $customer->city, 'our change stays');
        $this->assertSame('0381234567', $customer->phone, 'theirs came in');
        $this->assertSame(['Deventer', '0381234567'], [FakeConnector::$customers['A1']['city'], FakeConnector::$customers['A1']['phone']]);
        $this->assertSame(['update:A1'], FakeConnector::$calls, 'sent once, after the merge');
    }

    public function test_different_fields_changed_on_both_sides_both_survive_a_pull(): void
    {
        FakeConnector::$customers['A1'] = ['name' => 'Bakkerij Jansen', 'city' => 'Zwolle'];
        $this->pullCustomers();
        $this->customers(read: true, write: false, leading: 'external');

        Customer::where('name', 'Bakkerij Jansen')->firstOrFail()->update(['city' => 'Deventer']);
        FakeConnector::$customers['A1']['phone'] = '0381234567';

        $run = $this->pullCustomers();

        $customer = Customer::where('name', 'Bakkerij Jansen')->firstOrFail();
        $this->assertSame(['Deventer', '0381234567'], [$customer->city, $customer->phone]);
        $this->assertSame(['merged' => 1], $run->counts);
    }

    public function test_a_field_both_sides_changed_to_the_same_value_is_no_clash(): void
    {
        FakeConnector::$customers['A1'] = ['name' => 'Bakkerij Jansen', 'city' => 'Zwolle'];
        $this->pullCustomers();
        $this->customers(read: true, write: true, leading: 'lavoro');

        $this->withoutSending(fn () => Customer::where('name', 'Bakkerij Jansen')->firstOrFail()->update(['city' => 'Kampen']));
        FakeConnector::$customers['A1']['city'] = 'KAMPEN ';

        $run = $this->pullCustomers();

        $this->assertSame(['unchanged' => 1], $run->counts);
        $this->assertSame([], FakeConnector::$calls);
    }

    public function test_a_customer_made_here_is_created_there(): void
    {
        $customer = Customer::factory()->create(['name' => 'Garage Smit']);

        $this->assertNotNull($id = $customer->externalId($this->connection));
        $this->assertSame('Garage Smit', FakeConnector::$customers[$id]['name']);

        $this->pullCustomers();
        $this->assertSame(1, Customer::where('name', 'Garage Smit')->count(), 'the next pull does not bring it in twice');
    }

    public function test_an_existing_customer_is_recognised_by_kvk_number_instead_of_doubled(): void
    {
        $this->customers(read: true, write: false, leading: 'external');
        $customer = Customer::factory()->create(['name' => 'Smit BV', 'chamber_of_commerce_number' => '12345678']);
        FakeConnector::$customers['A9'] = ['name' => 'Smit B.V.', 'chamber_of_commerce_number' => '12345678'];

        $run = $this->pullCustomers();

        $this->assertSame(['linked' => 1], $run->counts);
        $this->assertSame('A9', $customer->fresh()->externalId($this->connection));
        $this->assertSame(0, Customer::where('name', 'Smit B.V.')->where('id', '!=', $customer->id)->count());
    }

    public function test_a_link_that_never_existed_there_is_dropped_and_a_real_one_marked_missing(): void
    {
        $this->customers(read: true, write: false, leading: 'external');
        FakeConnector::$customers['A1'] = ['name' => 'Blijft'];
        FakeConnector::$customers['A2'] = ['name' => 'Verdwijnt'];
        $this->pullCustomers();

        $made_up = Customer::factory()->create(['name' => 'Verzonnen id']);
        Integrationable::create([
            'integration_connection_id' => $this->connection->id,
            'integrationable_type' => $made_up->getMorphClass(),
            'integrationable_id' => $made_up->id,
            'external_id' => 'NEP',
        ]);
        unset(FakeConnector::$customers['A2']);

        $run = $this->pullCustomers();

        $this->assertSame(['unchanged' => 1, 'unlinked' => 1, 'missing' => 1], $run->counts);
        $this->assertNull($made_up->fresh()->externalId($this->connection));
        $this->assertNotNull(Customer::where('name', 'Verdwijnt')->firstOrFail()->integrationLink($this->connection)->missing_at);
    }

    public function test_an_empty_answer_marks_nothing_missing(): void
    {
        FakeConnector::$customers['A1'] = ['name' => 'Blijft'];
        $this->pullCustomers();
        FakeConnector::$customers = [];

        $this->pullCustomers();

        $this->assertNull(Customer::where('name', 'Blijft')->firstOrFail()->integrationLink($this->connection)->missing_at);
    }

    public function test_materials_come_in_and_are_recognised_by_code(): void
    {
        $existing = Material::create(['name' => 'Filter oud', 'code' => 'FLT-1', 'stock' => 3]);
        FakeConnector::$materials['M1'] = ['name' => 'Filter', 'code' => 'FLT-1', 'price' => 12.5];
        FakeConnector::$materials['M2'] = ['name' => 'Pakking', 'code' => 'PK-2', 'price' => 1.25];

        $run = $this->sync()->pull($this->connection, Area::Materials);

        $this->assertSame(['linked' => 1, 'created' => 1], $run->counts);
        $this->assertSame('Filter', $existing->fresh()->name);
        $this->assertSame('M1', $existing->fresh()->externalId($this->connection));
    }

    public function test_an_area_the_connection_does_not_read_is_refused(): void
    {
        $this->connection->update(['settings' => [...$this->connection->settings, 'materials' => ['read' => false]]]);

        $this->expectException(Refusal::class);

        $this->sync()->pull($this->connection->fresh(), Area::Materials);
    }

    public function test_a_failed_pull_changes_nothing_and_is_written_down(): void
    {
        FakeConnector::$customers['A1'] = ['name' => 'Eerste'];
        FakeConnector::$customers['A2'] = null;

        try {
            $this->pullCustomers();
            $this->fail('A broken record should have stopped the pull.');
        } catch (\Throwable) {
        }

        $this->assertSame(0, Customer::where('name', 'Eerste')->count());
        $this->assertSame(IntegrationRun::FAILED, $this->connection->runs()->latest('id')->first()->status);
        $this->assertNotNull($this->connection->fresh()->last_error);
    }

    public function test_the_quarterly_pull_asks_only_what_changed_since_the_last_pull(): void
    {
        $this->connection->runs()->delete();
        FakeConnector::$pulls = [];
        FakeConnector::$detection = ChangeDetection::Delta;
        FakeConnector::$customers['A1'] = ['name' => 'Oud'];
        $this->pullCustomers();

        $this->travel(20)->minutes();
        FakeConnector::$customers['A2'] = ['name' => 'Nieuw'];
        FakeConnector::$changed_at['A1'] = CarbonImmutable::now()->subHour();
        FakeConnector::$changed_at['A2'] = CarbonImmutable::now();

        $run = $this->sync()->pull($this->connection->fresh(), Area::Customers, changes_only: true);

        $this->assertNull(FakeConnector::$pulls[0], 'the first pull is a full one');
        $this->assertEquals(CarbonImmutable::now()->subMinutes(25)->startOfSecond(), FakeConnector::$pulls[1]->startOfSecond());
        $this->assertSame(['created' => 1], $run->counts, 'only what changed, and nothing counted as gone');
    }

    public function test_a_system_that_cannot_tell_what_changed_is_always_pulled_in_full(): void
    {
        FakeConnector::$pulls = [];
        FakeConnector::$customers['A1'] = ['name' => 'Oud'];
        $this->pullCustomers();

        $this->sync()->pull($this->connection->fresh(), Area::Customers, changes_only: true);

        $this->assertSame([null, null], FakeConnector::$pulls);
    }

    public function test_a_send_while_a_sync_of_the_same_area_runs_waits_its_turn_and_says_so(): void
    {
        Synchronizer::$wait_seconds = 0;
        $this->customers(read: true, write: false, leading: 'external');
        $customer = Customer::factory()->create(['name' => 'Garage Smit']);
        $this->customers(read: true, write: true, leading: 'external');
        $lock = Cache::lock('integration-sync:' . $this->connection->id . ':customers', 60);
        $lock->get();

        try {
            $this->sync()->push($this->connection->fresh(), Area::Customers, $customer);
            $this->fail('The send should have been turned away while the lock is held.');
        } catch (IntegrationUnavailable $e) {
            $this->assertStringContainsString('Er loopt al een synchronisatie', $e->getMessage());
        } finally {
            $lock->release();
            Synchronizer::$wait_seconds = 15;
        }

        $this->assertSame([], FakeConnector::$calls);
    }

    public function test_sending_a_change_to_a_record_gone_there_marks_it_and_stops_trying(): void
    {
        FakeConnector::$customers['A1'] = ['name' => 'Bakkerij Jansen'];
        $this->pullCustomers();
        $this->customers(read: false, write: true, leading: 'external');
        unset(FakeConnector::$customers['A1']);

        $customer = Customer::where('name', 'Bakkerij Jansen')->firstOrFail();
        $customer->update(['city' => 'Kampen']);

        $this->assertNotNull($customer->integrationLink($this->connection)->missing_at);
        $this->assertSame('missing', $this->sync()->push($this->connection->fresh(), Area::Customers, $customer->fresh()));
    }

    public function test_an_article_whose_code_belongs_to_another_material_stops_the_pull_in_words(): void
    {
        FakeConnector::$materials['M1'] = ['name' => 'Filter', 'code' => 'FLT-1'];
        $this->sync()->pull($this->connection, Area::Materials);
        unset(FakeConnector::$materials['M1']);
        FakeConnector::$materials['M2'] = ['name' => 'Filter nieuw', 'code' => 'FLT-1'];

        try {
            $this->sync()->pull($this->connection->fresh(), Area::Materials);
            $this->fail('The clashing code should have stopped the pull.');
        } catch (Refusal $e) {
            $this->assertStringContainsString('"Filter nieuw" kan niet worden opgeslagen', $e->getMessage());
        }

        $this->assertStringContainsString('Filter nieuw', $this->connection->fresh()->last_error);
    }

    public function test_a_pull_after_a_webhook_does_not_move_the_moment_changes_are_asked_from(): void
    {
        $this->connection->runs()->delete();
        FakeConnector::$detection = ChangeDetection::Delta;
        FakeConnector::$customers['A1'] = ['name' => 'Oud'];
        $this->pullCustomers();
        $pulled_at = CarbonImmutable::now();

        $this->travel(10)->minutes();
        (new PullRecordJob($this->connection->id, 'customers', 'A1'))->handle($this->sync());
        $this->travel(5)->minutes();
        FakeConnector::$pulls = [];

        $this->sync()->pull($this->connection->fresh(), Area::Customers, changes_only: true);

        $this->assertEquals($pulled_at->subMinutes(5)->startOfSecond(), FakeConnector::$pulls[0]->startOfSecond());
    }

    public function test_nothing_new_is_sent_before_the_first_complete_pull_so_nothing_is_made_twice(): void
    {
        $this->connection->runs()->delete();
        $customer = Customer::withoutEvents(fn () => Customer::factory()->create(['name' => 'Bakker BV']));

        try {
            $this->sync()->push($this->connection->fresh(), Area::Customers, $customer);
            $this->fail('A send before the first complete pull should wait.');
        } catch (IntegrationUnavailable $e) {
            $this->assertStringContainsString('nog niet voor het eerst helemaal opgehaald', $e->getMessage());
        }

        $this->assertSame([], FakeConnector::$calls);
    }

    public function test_switching_reading_on_starts_the_first_complete_pull_at_once(): void
    {
        FakeConnector::$customers['A1'] = ['name' => 'Er al'];
        $this->customers(read: false, write: false, leading: 'external');
        $this->connection->runs()->delete();

        $this->customers(read: true, write: false, leading: 'external');

        $this->assertTrue(Customer::where('name', 'Er al')->exists());
        $this->assertTrue($this->sync()->pulledInFull($this->connection->fresh(), Area::Customers));
    }

    public function test_a_made_up_link_does_not_stop_the_real_customer_from_being_recognised(): void
    {
        $this->customers(read: true, write: false, leading: 'external');
        $customer = Customer::factory()->create(['name' => 'Smit BV', 'chamber_of_commerce_number' => '12345678']);
        Integrationable::create([
            'integration_connection_id' => $this->connection->id,
            'integrationable_type' => $customer->getMorphClass(),
            'integrationable_id' => $customer->id,
            'external_id' => 'VERZONNEN',
        ]);
        FakeConnector::$customers['ECHT'] = ['name' => 'Smit BV', 'chamber_of_commerce_number' => '12345678'];

        $run = $this->pullCustomers();

        $this->assertSame(['linked' => 1], $run->counts);
        $this->assertSame('ECHT', $customer->fresh()->externalId($this->connection));
        $this->assertSame(1, Customer::where('name', 'Smit BV')->count());
    }

    public function test_a_made_up_link_refused_there_is_replaced_by_the_real_record(): void
    {
        $this->customers(read: false, write: true, leading: 'external');
        $customer = Customer::withoutEvents(fn () => Customer::factory()->create(['name' => 'Garage Smit']));
        Integrationable::create([
            'integration_connection_id' => $this->connection->id,
            'integrationable_type' => $customer->getMorphClass(),
            'integrationable_id' => $customer->id,
            'external_id' => 'VERZONNEN',
        ]);

        $outcome = $this->sync()->push($this->connection->fresh(), Area::Customers, $customer);

        $this->assertSame('created', $outcome);
        $this->assertNotSame('VERZONNEN', $id = $customer->fresh()->externalId($this->connection));
        $this->assertSame('Garage Smit', FakeConnector::$customers[$id]['name']);
    }
}

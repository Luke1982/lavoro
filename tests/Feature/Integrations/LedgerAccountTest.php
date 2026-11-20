<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Connectors;
use App\Domain\Integrations\Sync\ServiceOrderSender;
use App\Domain\Integrations\Sync\Synchronizer;
use App\Models\Customer;
use App\Models\IntegrationConnection;
use App\Models\LedgerAccount;
use App\Models\Material;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\Fakes\FakeConnector;
use Tests\TestCase;

/**
 * The revenue accounts materials are booked on: made here or fetched, kept in
 * step like customers, and carried on every werkbon line that goes out.
 */
class LedgerAccountTest extends TestCase
{
    use CreatesAuthenticatedUsers;

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
                'ledger_accounts' => ['read' => true, 'write' => false, 'leading' => 'external'],
                'service_orders' => ['form' => 'final_invoice'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Connectors::forgetExtensions();

        parent::tearDown();
    }

    private function manager()
    {
        return $this->actingAs($this->userWith('integration.manage'));
    }

    private function pull(): void
    {
        app(Synchronizer::class)->pull($this->connection->fresh(), Area::LedgerAccounts);
    }

    private function exchange(bool $read, bool $write): void
    {
        $this->connection->update(['settings' => [
            ...$this->connection->settings,
            'ledger_accounts' => ['read' => $read, 'write' => $write, 'leading' => 'external'],
        ]]);
    }

    public function test_accounts_come_in_and_one_already_here_is_recognised_by_its_number(): void
    {
        $existing = LedgerAccount::create(['code' => '8000', 'name' => 'Omzet']);
        FakeConnector::$ledger_accounts = [
            'gb-1' => ['code' => '8000', 'name' => 'Omzet hoog'],
            'gb-2' => ['code' => '8100', 'name' => 'Omzet laag'],
        ];

        $this->pull();

        $this->assertSame(2, LedgerAccount::count());
        $this->assertSame('gb-1', $existing->externalId($this->connection));
        $this->assertSame('Omzet hoog', $existing->fresh()->name, 'the system leads');
        $this->assertSame('gb-2', LedgerAccount::where('code', '8100')->firstOrFail()->externalId($this->connection));
    }

    public function test_an_account_made_here_goes_to_a_system_that_takes_them(): void
    {
        $this->pull();
        $this->exchange(read: true, write: true);

        $this->manager()->post('/integrations/ledger-accounts', ['code' => '8200', 'name' => 'Omzet service'])
            ->assertSessionHasNoErrors();

        $account = LedgerAccount::where('code', '8200')->firstOrFail();
        $id = $account->externalId($this->connection);
        $this->assertNotNull($id, 'linked to what was made there');
        $this->assertSame(['name' => 'Omzet service', 'code' => '8200'], FakeConnector::$ledger_accounts[$id]);
    }

    public function test_one_made_here_needs_a_number_of_its_own(): void
    {
        LedgerAccount::create(['code' => '8000', 'name' => 'Omzet']);

        $this->manager()->post('/integrations/ledger-accounts', ['name' => 'Zonder nummer'])->assertSessionHasErrors('code');
        $this->manager()->post('/integrations/ledger-accounts', ['code' => '8000', 'name' => 'Dubbel'])
            ->assertSessionHasErrors(['code' => 'Er is al een grootboekrekening met dit nummer.']);

        $account = LedgerAccount::firstOrFail();
        $this->manager()->put('/integrations/ledger-accounts/' . $account->id, ['code' => '8000', 'name' => 'Omzet hoog'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Omzet hoog', $account->fresh()->name, 'its own number is no clash');
    }

    public function test_one_fetched_without_a_number_can_be_renamed_without_one(): void
    {
        $account = LedgerAccount::create(['name' => 'Omzet uit Moneybird']);

        $this->manager()->put('/integrations/ledger-accounts/' . $account->id, ['code' => null, 'name' => 'Omzet'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Omzet', $account->fresh()->name);
    }

    public function test_choosing_the_account_of_a_material_needs_the_right_to_change_materials(): void
    {
        $account = LedgerAccount::create(['code' => '8000', 'name' => 'Omzet']);
        $material = Material::create(['name' => 'Filter', 'stock' => 0]);
        $values = ['name' => 'Filter', 'stock' => 0, 'min_stock' => 0, 'max_stock' => 0, 'ledger_account_id' => $account->id];

        $this->actingAs($this->userWith('material.read'))->patchJson('/materials/' . $material->id, $values)->assertForbidden();
        $this->actingAs($this->userWithPermissions('material.read', 'material.update'))->patch('/materials/' . $material->id, $values)
            ->assertSessionHasNoErrors();

        $this->assertSame($account->id, $material->fresh()->ledger_account_id);
    }

    public function test_an_account_still_chosen_somewhere_cannot_be_removed(): void
    {
        $account = LedgerAccount::create(['code' => '8000', 'name' => 'Omzet']);
        $material = Material::create(['name' => 'Filter', 'stock' => 0, 'ledger_account_id' => $account->id]);

        $this->manager()->delete('/integrations/ledger-accounts/' . $account->id)
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'hoort nog bij 1 materiaal'));
        $this->assertModelExists($account);

        $material->update(['ledger_account_id' => null]);

        $this->manager()->delete('/integrations/ledger-accounts/' . $account->id)->assertSessionHas('success');
        $this->assertModelMissing($account);
    }

    public function test_the_list_is_for_whoever_manages_the_connections(): void
    {
        $this->actingAs($this->userWith('material.read'))->getJson('/integrations/ledger-accounts')->assertForbidden();
        $this->actingAs($this->userWith('material.read'))->postJson('/integrations/ledger-accounts', ['code' => '1', 'name' => 'x'])->assertForbidden();
        $this->manager()->get('/integrations/ledger-accounts')->assertOk();
    }

    public function test_a_material_shows_the_choice_only_while_a_connection_exchanges_them(): void
    {
        LedgerAccount::create(['code' => '8000', 'name' => 'Omzet']);
        $material = Material::create(['name' => 'Filter', 'stock' => 0]);
        $reader = $this->userWith('material.read');

        $this->actingAs($reader)->get('/materials/' . $material->id)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('ledgerAccounts', [['id' => LedgerAccount::first()->id, 'name' => '8000 Omzet']]));

        $this->exchange(read: false, write: false);

        $this->actingAs($reader)->get('/materials/' . $material->id)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('ledgerAccounts', null));
    }

    public function test_the_menu_offers_the_list_only_while_a_connection_exchanges_them(): void
    {
        $this->manager()->get('/integrations')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('features.ledger_accounts', true));

        $this->exchange(read: false, write: false);

        $this->manager()->get('/integrations')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('features.ledger_accounts', false));
    }

    public function test_a_werkbon_line_goes_onto_the_account_of_its_material(): void
    {
        FakeConnector::$ledger_accounts = ['gb-1' => ['code' => '8000', 'name' => 'Omzet']];
        $this->pull();
        $linked = LedgerAccount::where('code', '8000')->firstOrFail();
        $unlinked = LedgerAccount::create(['code' => '8999', 'name' => 'Alleen hier']);

        $order = $this->closedOrder([
            Material::create(['name' => 'Filter', 'price' => 12.5, 'stock' => 0, 'ledger_account_id' => $linked->id]),
            Material::create(['name' => 'Pakking', 'price' => 2, 'stock' => 0, 'ledger_account_id' => $unlinked->id]),
            Material::create(['name' => 'Bout', 'price' => 1, 'stock' => 0]),
        ]);

        $sent = app(ServiceOrderSender::class)->send($order);

        $lines = collect(FakeConnector::$documents[0]['document']->lines)->keyBy('description');
        $this->assertSame('gb-1', $lines['Filter']->ledger_account_id);
        $this->assertNull($lines['Pakking']->ledger_account_id);
        $this->assertNull($lines['Bout']->ledger_account_id);
        $this->assertSame(['Grootboekrekening 8999 Alleen hier van Pakking staat niet in Nepboekhouding.'], $sent[0]['document']->warnings);
    }

    /** Once the field is hidden, a choice made before cannot be seen or changed, so it is not used either. */
    public function test_a_connection_that_no_longer_exchanges_accounts_gets_none_on_its_lines(): void
    {
        FakeConnector::$ledger_accounts = ['gb-1' => ['code' => '8000', 'name' => 'Omzet']];
        $this->pull();
        $this->exchange(read: false, write: false);

        $order = $this->closedOrder([
            Material::create(['name' => 'Filter', 'price' => 12.5, 'stock' => 0, 'ledger_account_id' => LedgerAccount::firstOrFail()->id]),
        ]);

        $sent = app(ServiceOrderSender::class)->send($order);

        $this->assertNull(FakeConnector::$documents[0]['document']->lines[0]->ledger_account_id);
        $this->assertSame([], $sent[0]['document']->warnings);
    }

    /** @param  array<int, Material>  $materials */
    private function closedOrder(array $materials): ServiceOrder
    {
        $order = ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create(['name' => 'Garage Smit'])->id,
            'service_order_stage_id' => ServiceOrderStage::create(['name' => 'Gesloten', 'order' => 9, 'is_closed_state' => true])->id,
        ]);

        foreach ($materials as $material) {
            $order->materials()->attach($material->id, ['quantity' => 1]);
        }

        return $order;
    }
}

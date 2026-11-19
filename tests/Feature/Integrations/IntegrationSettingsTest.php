<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Connectors;
use App\Models\Central\IntegrationWebhookLookup;
use App\Models\Customer;
use App\Models\IntegrationConnection;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\Fakes\FakeConnector;
use Tests\TestCase;

/**
 * Instellingen → Koppelingen, and the webhook address a linked system calls.
 */
class IntegrationSettingsTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        FakeConnector::reset();
        Connectors::extend(FakeConnector::class);
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

    public function test_the_page_needs_its_own_permission(): void
    {
        $this->actingAs($this->userWith('customer.read'))->getJson('/integrations')->assertForbidden();
        $this->manager()->get('/integrations')->assertOk();
    }

    public function test_connecting_stores_the_key_encrypted_and_never_sends_it_back(): void
    {
        $this->manager()->post('/integrations', [
            'connector' => 'fake',
            'credentials' => ['api_key' => 'heel-geheime-sleutel'],
        ])->assertSessionHasNoErrors();

        $connection = IntegrationConnection::where('connector', 'fake')->firstOrFail();
        $this->assertSame('heel-geheime-sleutel', $connection->credentials()['api_key']);
        $this->assertTrue($connection->reads(Area::Customers));
        $this->assertFalse($connection->writes(Area::Customers), 'sending customers is a choice');

        $page = $this->manager()->get('/integrations')->getContent();
        $this->assertStringNotContainsString('heel-geheime-sleutel', $page);
    }

    public function test_an_empty_secret_on_saving_keeps_the_stored_one(): void
    {
        $connection = $this->connect();

        $this->manager()->put('/integrations/' . $connection->id, [
            'is_active' => true,
            'credentials' => ['api_key' => ''],
            'areas' => ['customers' => ['read' => true, 'write' => true, 'leading' => 'lavoro']],
        ])->assertSessionHasNoErrors();

        $connection->refresh();
        $this->assertSame('goed', $connection->credentials()['api_key']);
        $this->assertTrue($connection->writes(Area::Customers));
    }

    public function test_a_form_the_system_cannot_make_is_refused(): void
    {
        $this->manager()->post('/integrations', [
            'connector' => 'eboekhouden',
            'credentials' => ['username' => 'jan', 'security_code_1' => 'a', 'security_code_2' => 'b'],
            'areas' => ['service_orders' => ['form' => 'sales_order']],
        ])->assertSessionHasErrors('areas.service_orders.form');
    }

    public function test_the_test_button_says_what_is_wrong(): void
    {
        $connection = $this->connect('fout');

        $this->manager()->post('/integrations/' . $connection->id . '/test')
            ->assertSessionHas('error', 'Nepboekhouding weigert de sleutel.');
    }

    public function test_reading_an_area_with_webhooks_subscribes_one_and_its_address_pulls_the_record(): void
    {
        $connection = $this->connect();
        $webhook = $connection->webhooks()->firstOrFail();

        $this->assertCount(1, FakeConnector::$subscriptions);
        $this->assertTrue(IntegrationWebhookLookup::whereKey(hash('sha256', $webhook->token))->exists());

        FakeConnector::$customers['W1'] = ['name' => 'Via webhook BV'];

        $this->postJson('/integrations/webhook/' . $webhook->token, ['changed' => ['W1']])->assertStatus(202);

        $this->assertTrue(Customer::where('name', 'Via webhook BV')->exists());
    }

    public function test_an_unknown_webhook_token_is_turned_away(): void
    {
        $this->connect();

        $this->postJson('/integrations/webhook/' . str_repeat('x', 48), ['changed' => ['W1']])->assertNotFound();
    }

    public function test_the_handshake_is_answered(): void
    {
        $webhook = $this->connect()->webhooks()->firstOrFail();

        $this->post('/integrations/webhook/' . $webhook->token . '?validationToken=abc123')
            ->assertOk()
            ->assertSee('abc123');
    }

    public function test_switching_the_area_off_removes_its_webhook(): void
    {
        $connection = $this->connect();
        $token = $connection->webhooks()->firstOrFail()->token;

        $connection->update(['settings' => [...$connection->settings, 'customers' => ['read' => false]]]);

        $this->assertSame(0, $connection->webhooks()->count());
        $this->assertFalse(IntegrationWebhookLookup::whereKey(hash('sha256', $token))->exists());
    }

    public function test_pulling_by_hand_needs_the_pull_permission(): void
    {
        $connection = $this->connect();

        $this->actingAs($this->userWith('customer.read'))
            ->postJson('/integrations/' . $connection->id . '/pull/customers')
            ->assertForbidden();

        FakeConnector::$customers['P1'] = ['name' => 'Handmatig BV'];

        $this->actingAs($this->userWith('integration.pull'))
            ->post('/integrations/' . $connection->id . '/pull/customers')
            ->assertSessionHas('success', 'Uit Nepboekhouding: 1 nieuw.');
    }

    public function test_switching_on_what_the_system_cannot_do_is_refused_in_words(): void
    {
        $connection = $this->connect();

        $this->manager()->put('/integrations/' . $connection->id, [
            'is_active' => true,
            'areas' => ['materials' => ['read' => true, 'write' => true]],
        ])->assertSessionHasErrors(['areas.materials.write' => 'Nepboekhouding kan dit niet.']);
    }

    private function connect(string $key = 'goed'): IntegrationConnection
    {
        $connection = new IntegrationConnection([
            'connector' => 'fake',
            'is_active' => true,
            'settings' => IntegrationConnection::defaultSettings('fake'),
        ]);
        $connection->storeCredentials(['api_key' => $key]);
        $connection->save();

        return $connection;
    }
}

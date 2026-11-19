<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Connectors;
use App\Domain\Integrations\DocumentForm;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\IntegrationConnection;
use App\Models\Material;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\Fakes\FakeConnector;
use Tests\TestCase;

/**
 * "Send to the administration" on a werkbon, through the one door every linked
 * system shares.
 */
class ServiceOrderSendingTest extends TestCase
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
                'service_orders' => ['form' => 'sales_order'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Connectors::forgetExtensions();

        parent::tearDown();
    }

    private function closedOrder(array $attributes = []): ServiceOrder
    {
        $stage = ServiceOrderStage::create(['name' => 'Gesloten', 'order' => 9, 'is_closed_state' => true]);
        $order = ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create(['name' => 'Garage Smit'])->id,
            'service_order_stage_id' => $stage->id,
            ...$attributes,
        ]);

        $material = Material::create(['name' => 'Filter', 'code' => 'FLT-1', 'price' => 12.5, 'stock' => 0]);
        $order->materials()->attach($material->id, ['quantity' => 2]);

        return $order;
    }

    private function send(ServiceOrder $order)
    {
        return $this->actingAs($this->userWithPermissions('serviceorder.read', 'integration.send_serviceorder'))
            ->post('/serviceorders/' . $order->id . '/send-to-administration');
    }

    private function form(string $form): void
    {
        $this->connection->update(['settings' => [...$this->connection->settings, 'service_orders' => ['form' => $form]]]);
    }

    public function test_a_closed_werkbon_goes_across_with_its_lines_and_is_marked_sent(): void
    {
        $order = $this->closedOrder(['external_purchaseorder_no' => 'PO-77']);

        $this->send($order)->assertSessionHas('success')->assertSessionMissing('error');

        $this->assertCount(1, FakeConnector::$documents);
        $document = FakeConnector::$documents[0]['document'];
        $this->assertSame('Werkbon ' . $order->id, $document->reference);
        $this->assertSame('PO-77', $document->memo);
        $this->assertCount(1, $document->lines);
        $this->assertSame(2.0, $document->lines[0]->quantity);
        $this->assertSame(25.0, $document->lines[0]->total());
        $this->assertTrue($order->fresh()->sent_to_administration);
        $this->assertNotNull($order->fresh()->externalId($this->connection));
    }

    public function test_a_customer_not_there_yet_is_sent_along_first(): void
    {
        $order = $this->closedOrder();
        $order->customer->integrationLinks()->delete();

        $this->send($order)->assertSessionHas('success');

        $customer_id = FakeConnector::$documents[0]['document']->customer_id;
        $this->assertSame('Garage Smit', FakeConnector::$customers[$customer_id]['name']);
    }

    public function test_without_sending_customers_an_unlinked_customer_is_refused_with_the_reason(): void
    {
        $this->connection->update(['settings' => [...$this->connection->settings, 'customers' => ['read' => true, 'write' => false]]]);
        $order = $this->closedOrder();

        $this->send($order)->assertSessionHas('error', fn (string $message) => str_contains($message, 'niet gekoppeld'));

        $this->assertSame([], FakeConnector::$documents);
        $this->assertFalse($order->fresh()->sent_to_administration);
    }

    public function test_an_open_werkbon_is_refused(): void
    {
        $order = $this->closedOrder();
        $order->update(['service_order_stage_id' => ServiceOrderStage::create(['name' => 'Open', 'order' => 1])->id]);

        $this->send($order)->assertSessionHas('error', fn (string $message) => str_contains($message, 'Sluit de werkbon'));
        $this->assertSame([], FakeConnector::$documents);
    }

    public function test_a_refusal_there_is_shown_and_written_down(): void
    {
        FakeConnector::$refuse = true;
        $order = $this->closedOrder();

        $this->send($order)->assertSessionHas('error', 'Nepboekhouding weigert deze werkbon.');

        $this->assertFalse($order->fresh()->sent_to_administration);
        $this->assertSame('Nepboekhouding weigert deze werkbon.', $this->connection->fresh()->last_error);
    }

    public function test_a_final_invoice_number_becomes_the_werkbons_invoice_number(): void
    {
        $this->form(DocumentForm::FinalInvoice->value);
        $order = $this->closedOrder();

        $this->send($order);

        $this->assertStringStartsWith('F-', (string) $order->fresh()->external_invoice_no);
    }

    public function test_the_trail_says_where_it_went(): void
    {
        $order = $this->closedOrder();

        $this->send($order);

        $this->assertTrue(Activity::where('event_key', 'integration.serviceorder_sent')->exists());
    }

    public function test_without_a_connection_that_takes_werkbonnen_it_says_so(): void
    {
        $this->form('');
        $order = $this->closedOrder();

        $this->send($order)->assertSessionHas('error', fn (string $message) => str_contains($message, 'geen koppeling'));
    }

    public function test_a_werkbon_that_is_being_sent_cannot_be_sent_again_meanwhile(): void
    {
        $order = $this->closedOrder();
        $lock = Cache::lock('serviceorder-send:' . $order->id, 60);
        $lock->get();

        try {
            $this->send($order)->assertSessionHas('error', fn (string $message) => str_contains($message, 'wordt al verstuurd'));
        } finally {
            $lock->release();
        }

        $this->assertSame([], FakeConnector::$documents);
    }

    public function test_an_article_removed_there_goes_without_its_id(): void
    {
        $order = $this->closedOrder();
        $material = $order->materials()->firstOrFail();
        $material->integrationLinks()->create([
            'integration_connection_id' => $this->connection->id,
            'external_id' => 'WEG',
            'confirmed_at' => now(),
            'missing_at' => now(),
        ]);

        $this->send($order);

        $this->assertNull(FakeConnector::$documents[0]['document']->lines[0]->article_id);
    }
}

<?php

namespace Tests\Feature\Quotes;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventUserExecution;
use App\Models\GeneralSetting;
use App\Models\Material;
use App\Models\Product;
use App\Models\Project;
use App\Models\Quote;
use App\Models\QuoteTemplate;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\Concerns\MakesQuotes;
use Tests\TestCase;

/**
 * The ways a quote starts from something that is already there: a template, a
 * copy of another quote, or a werkbon or storing where a technician found work
 * that needs an offer first.
 */
class QuoteStartTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use MakesQuotes;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->signIn($this->admin());
    }

    public function test_a_template_keeps_the_lines_and_terms_and_starts_new_quotes_with_them(): void
    {
        $source = $this->makeQuote();
        $this->addLine($source, 1000, 'Unit', ['location_note' => 'woonkamer']);
        $this->addLine($source, 50, 'Condenspomp', ['is_optional' => true]);
        $this->patch("/quotes/{$source->id}", ['start_period' => 'week 14', 'pricing_method' => 'Regie']);

        $this->post("/quotes/{$source->id}/template", ['name' => 'Airco 1 ruimte'])->assertSessionHasNoErrors();
        $template = QuoteTemplate::sole();

        $this->post('/quotes', ['title' => 'Nieuw', 'customer_id' => Customer::factory()->create()->id, 'template_id' => $template->id])
            ->assertSessionHasNoErrors();

        $quote = Quote::latest('id')->first();
        $this->assertSame(['Unit', 'Condenspomp'], $quote->lines()->pluck('description')->all());
        $this->assertSame('woonkamer', $quote->lines()->first()->location_note);
        $this->assertSame('week 14', $quote->start_period);
        $this->assertSame('Regie', $quote->pricing_method->value);
        $this->assertTrue($quote->origin->is($template));
        $this->assertSame(1, $this->trailEntries($quote, "gemaakt vanuit sjabloon 'Airco 1 ruimte'"));
    }

    public function test_a_copy_is_a_new_concept_for_the_customer_chosen(): void
    {
        $source = $this->makeQuote();
        $this->addLine($source, 1000, 'Unit');
        $this->sendQuote($source);
        $other = Customer::factory()->create();

        $this->post("/quotes/{$source->id}/duplicate", ['title' => 'Kopie', 'customer_id' => $other->id])->assertRedirect();

        $copy = Quote::latest('id')->first();
        $this->assertSame($other->id, $copy->customer_id);
        $this->assertSame('Concept', $copy->status);
        $this->assertSame(1, $copy->version);
        $this->assertSame(0, $copy->versions()->count());
        $this->assertSame(['Unit'], $copy->lines()->pluck('description')->all());
        $this->assertTrue($copy->origin->is($source));
    }

    public function test_a_werkbon_offers_its_storingen_materials_and_hours_and_hears_of_the_quote(): void
    {
        $customer = Customer::factory()->create();
        $order = ServiceOrder::factory()->create(['customer_id' => $customer->id]);
        $material = Material::create(['name' => 'Koelleiding 1/4', 'price' => 12.5]);
        $order->materials()->attach($material->id, ['quantity' => 6, 'unforseen' => false]);
        $order->freeformMaterials()->create(['description' => 'Kabelgoot', 'quantity' => 2, 'unforseen' => true]);
        $this->workedOn($order, hours: 3);
        $ticket = $this->ticketFor($customer, ['subject' => 'Lekt water', 'service_order_id' => $order->id]);
        GeneralSetting::set('quote_hourly_rate', 65);

        $start = $this->getJson("/quotes/origin/serviceorder/{$order->id}")->assertOk();
        $this->assertSame($customer->id, $start->json('customer.id'));
        $this->assertSame(
            ['Storing: Lekt water', 'Koelleiding 1/4', 'Kabelgoot', 'Gewerkte uren'],
            array_column($start->json('choices'), 'label'),
        );

        $this->post('/quotes', [
            'title' => $start->json('title'),
            'customer_id' => $customer->id,
            'origin_type' => 'serviceorder',
            'origin_id' => $order->id,
            'origin_lines' => ['ticket:' . $ticket->id, 'material:' . $order->materials()->first()->pivot->id, 'hours'],
        ])->assertSessionHasNoErrors();

        $quote = Quote::latest('id')->first();
        $lines = $quote->lines()->get();
        $this->assertSame(['Lekt water', 'Koelleiding 1/4', 'Arbeid'], $lines->pluck('description')->all());
        $this->assertSame(6.0, $lines[1]->quantity);
        $this->assertSame([3.0, 65.0], [$lines[2]->quantity, $lines[2]->unit_price]);
        $this->assertSame(1, $this->trailEntries($order, 'gemaakt vanuit werkbon ' . $order->number));
    }

    public function test_a_werkbon_offers_only_its_own_customers_project_and_address(): void
    {
        $customer = Customer::factory()->create();
        $foreign_project = Project::create([
            'title' => 'Van een ander',
            'customer_id' => Customer::factory()->create()->id,
            'project_manager_id' => auth()->id(),
            'status' => 'Niet gestart',
        ]);
        $order = ServiceOrder::factory()->create(['customer_id' => $customer->id, 'project_id' => $foreign_project->id]);

        $this->getJson("/quotes/origin/serviceorder/{$order->id}")
            ->assertOk()
            ->assertJson(['customer_id' => $customer->id, 'project_id' => null]);
    }

    public function test_a_storing_starts_a_quote_for_its_machines_customer(): void
    {
        $customer = Customer::factory()->create();
        $ticket = $this->ticketFor($customer, ['subject' => 'Compressor maakt herrie']);

        $this->getJson("/quotes/origin/ticket/{$ticket->id}")
            ->assertOk()
            ->assertJson(['title' => 'Compressor maakt herrie', 'customer_id' => $customer->id]);
    }

    public function test_starting_from_a_werkbon_asks_the_right_to_open_it(): void
    {
        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->actingAs($this->userWithPermissions('quote.read', 'quote.create'));

        $this->getJson("/quotes/origin/serviceorder/{$order->id}")->assertForbidden();
        $this->getJson('/quotes/origin/unknown/1')->assertNotFound();
    }

    private function workedOn(ServiceOrder $order, int $hours): void
    {
        $technician = User::factory()->create();
        $event = Event::factory()->create();
        $order->events()->attach($event->id);
        $event->executingUsers()->attach($technician->id, ['type' => 'executing', 'breaktime' => 0]);
        EventUserExecution::create([
            'event_id' => $event->id,
            'user_id' => $technician->id,
            'actual_start' => now()->setTime(8, 0),
            'actual_end' => now()->setTime(8 + $hours, 0),
        ]);
    }

    private function ticketFor(Customer $customer, array $attributes): Ticket
    {
        $asset = Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => Product::factory()->create()->id]);

        return Ticket::factory()->create(['asset_id' => $asset->id, 'description' => 'Druppelt sinds gisteren', ...$attributes]);
    }
}

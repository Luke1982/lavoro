<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Services\StandardEmailRenderer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StandardEmailRenderingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Event times are stored in UTC and rendered as Europe/Amsterdam
     * wall-clock, so the times asserted below are deliberately offset from the
     * ones stored here.
     */
    private function event_with_customer(
        string $start_utc = '2026-07-08 09:30:00',
        string $end_utc = '2026-07-08 11:00:00'
    ): Event {
        $customer = Customer::factory()->create([
            'name' => 'Klant BV',
            'email' => 'klant@example.com',
        ]);

        $event = Event::factory()->create([
            'name' => 'Onderhoud',
            'location' => 'Hoofdstraat 1',
            'start' => Carbon::parse($start_utc, 'UTC'),
            'end' => Carbon::parse($end_utc, 'UTC'),
        ]);
        $event->customers()->attach($customer->id);

        return $event->fresh(['serviceOrders.customer', 'customers']);
    }

    public function test_render_substitutes_all_placeholders_from_live_event_data(): void
    {
        $event = $this->event_with_customer();

        $body = 'Beste {{customer_name}}, uw afspraak {{event_name}} op {{event_start_date}} '
            . 'van {{event_start_time}} tot {{event_end_time}} ({{event_end_date}}) op {{event_location}}.';

        $rendered = StandardEmailRenderer::render($body, $event);

        $this->assertStringContainsString('Beste Klant BV', $rendered);
        $this->assertStringContainsString('afspraak Onderhoud', $rendered);
        $this->assertStringContainsString('08-07-2026', $rendered);
        $this->assertStringContainsString('11:30', $rendered);
        $this->assertStringContainsString('13:00', $rendered);
        $this->assertStringContainsString('Hoofdstraat 1', $rendered);
        $this->assertStringNotContainsString('{{', $rendered);
    }

    public function test_summer_times_are_rendered_in_central_european_summer_time(): void
    {
        $event = $this->event_with_customer('2026-07-08 09:30:00', '2026-07-08 11:00:00');

        $rendered = StandardEmailRenderer::render('{{event_start_time}} - {{event_end_time}}', $event);

        $this->assertSame('11:30 - 13:00', $rendered);
    }

    public function test_winter_times_are_rendered_in_central_european_time(): void
    {
        $event = $this->event_with_customer('2026-01-08 09:30:00', '2026-01-08 11:00:00');

        $rendered = StandardEmailRenderer::render('{{event_start_time}} - {{event_end_time}}', $event);

        $this->assertSame('10:30 - 12:00', $rendered);
    }

    public function test_a_date_crosses_over_when_the_local_day_differs_from_utc(): void
    {
        $event = $this->event_with_customer('2026-07-08 22:30:00', '2026-07-08 23:00:00');

        $rendered = StandardEmailRenderer::render('{{event_start_date}} {{event_start_time}}', $event);

        $this->assertSame('09-07-2026 00:30', $rendered);
    }

    public function test_default_recipient_is_customer_email(): void
    {
        $event = $this->event_with_customer();

        $this->assertEquals('klant@example.com', StandardEmailRenderer::defaultRecipient($event));
    }

    public function test_default_recipient_is_null_without_customer(): void
    {
        $event = Event::factory()->create()->fresh(['serviceOrders.customer', 'customers']);

        $this->assertNull(StandardEmailRenderer::defaultRecipient($event));
    }

    public function test_placeholders_are_advertised_for_the_editor(): void
    {
        $tokens = array_column(StandardEmailRenderer::placeholders(), 'token');

        $this->assertContains('{{event_start_date}}', $tokens);
        $this->assertContains('{{customer_name}}', $tokens);
    }
}

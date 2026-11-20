<?php

namespace Tests\Feature\Quotes;

use App\Actions\Quotes\ToggleQuoteAlternativeAction;
use App\Models\Quote;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\Concerns\MakesQuotes;
use Tests\TestCase;

/**
 * An accepted quote becomes the werkbon that carries it out: what the customer
 * took goes over, with where it goes, and nothing they let go of.
 */
class QuoteToServiceOrderTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use MakesQuotes;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->signIn($this->admin());
    }

    public function test_only_what_was_taken_goes_over_with_the_room_it_is_for(): void
    {
        $quote = $this->makeQuote();
        $this->addLine($quote, 1000, 'Single-split', ['location_note' => 'woonkamer']);
        $multi = $this->addLine($quote, 1800, 'Multi-split', ['location_note' => 'slaapkamers']);
        app(ToggleQuoteAlternativeAction::class)->execute($multi);
        $this->addLine($quote, 200, 'Condenspomp', ['is_optional' => true]);
        $this->addLine($quote, 150, 'Montage');

        $this->post("/quotes/{$quote->id}/accept", [
            'name' => 'Jan Klant',
            'signature' => $this->signature(),
            'options' => [$multi->id],
            'make_service_order' => true,
        ])->assertSessionHasNoErrors();

        $order = ServiceOrder::findOrFail($quote->fresh()->service_order_id);
        $this->assertEqualsCanonicalizing(
            ['Multi-split (slaapkamers)', 'Montage'],
            $order->freeformMaterials()->pluck('description')->all(),
        );
    }

    public function test_a_quote_without_an_answer_makes_no_werkbon(): void
    {
        $quote = $this->makeQuote();
        $this->addLine($quote);

        $this->post("/quotes/{$quote->id}/service-order")->assertSessionHas('error');

        $this->assertNull($quote->fresh()->service_order_id);
        $this->assertSame(0, ServiceOrder::where('description', 'like', 'Offerte ' . $quote->number . '%')->count());
    }
}

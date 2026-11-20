<?php

namespace Tests\Feature\Quotes;

use App\Enums\QuoteStatuses;
use App\Models\QuoteLostReason;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\Concerns\MakesQuotes;
use Tests\TestCase;

/**
 * A quote that is lost, and why: the customer says no on their page with a
 * reason meant for them, or a colleague marks it after a phone call. The list
 * can then be read by reason.
 */
class QuoteDeclineTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use MakesQuotes;

    private User $maker;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->maker = $this->signIn($this->admin());
    }

    public function test_the_customer_declines_with_a_reason_and_the_maker_hears_it(): void
    {
        $quote = $this->makeQuote();
        $this->addLine($quote);
        $link = $this->sendQuote($quote);
        $too_expensive = QuoteLostReason::where('name', 'Te duur')->sole();
        $this->signOut();

        $this->post($link . '/afwijzen', [
            'name' => 'Jan Klant',
            'lost_reason_id' => $too_expensive->id,
            'message' => 'Andere offerte was goedkoper',
        ])->assertSessionHasNoErrors();

        $quote->refresh();
        $this->assertSame(QuoteStatuses::afgewezen->value, $quote->status);
        $this->assertSame($too_expensive->id, $quote->lost_reason_id);
        $this->assertSame('Andere offerte was goedkoper', $quote->lost_reason_note);
        $this->assertSame(1, UserNotification::where('user_id', $this->maker->id)->where('type', 'quote.declined')->count());
        $this->get($link)->assertStatus(410);
    }

    public function test_the_customer_cannot_pick_a_reason_meant_for_colleagues(): void
    {
        $quote = $this->makeQuote();
        $this->addLine($quote);
        $link = $this->sendQuote($quote);
        $this->signOut();

        $this->post($link . '/afwijzen', ['name' => 'Jan', 'lost_reason_id' => QuoteLostReason::where('name', 'Geen reactie')->value('id')])
            ->assertSessionHasErrors('lost_reason_id');
    }

    public function test_a_colleague_marks_a_quote_lost_with_why_and_reopening_forgets_it(): void
    {
        $quote = $this->makeQuote();
        $reason = QuoteLostReason::where('name', 'Gaat niet door')->sole();

        $this->patch("/quotes/{$quote->id}/status", [
            'status' => QuoteStatuses::afgewezen->value,
            'lost_reason_id' => $reason->id,
            'lost_reason_note' => 'Verbouwing uitgesteld',
        ])->assertSessionHasNoErrors();

        $this->assertSame($reason->id, $quote->fresh()->lost_reason_id);
        $this->assertSame(0, UserNotification::where('type', 'quote.declined')->count(), 'Whoever marked it knows.');

        $this->patch("/quotes/{$quote->id}/status", ['status' => QuoteStatuses::concept->value])->assertSessionHasNoErrors();

        $this->assertNull($quote->fresh()->lost_reason_id);
        $this->assertNull($quote->fresh()->lost_reason_note);
    }

    public function test_a_reason_only_goes_with_declining(): void
    {
        $quote = $this->makeQuote();
        $this->addLine($quote);

        $this->patch("/quotes/{$quote->id}/status", [
            'status' => QuoteStatuses::verzonden->value,
            'lost_reason_id' => QuoteLostReason::value('id'),
        ])->assertSessionHasErrors('lost_reason_id');
    }

    public function test_the_list_is_read_by_reason(): void
    {
        $reason = QuoteLostReason::where('name', 'Te duur')->sole();
        $lost = $this->makeQuote();
        $this->makeQuote();
        $this->patch("/quotes/{$lost->id}/status", ['status' => QuoteStatuses::afgewezen->value, 'lost_reason_id' => $reason->id]);

        $this->get('/quotes?status=all&reason=' . $reason->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('quotes.data', 1)
                ->where('quotes.data.0.id', $lost->id)
                ->where('quotes.data.0.lost_reason', 'Te duur'));
    }
}

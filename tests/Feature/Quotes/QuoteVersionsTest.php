<?php

namespace Tests\Feature\Quotes;

use App\Actions\Quotes\StartNewQuoteVersionAction;
use App\Enums\QuoteStatuses;
use App\Exceptions\Refusal;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\Concerns\MakesQuotes;
use Tests\TestCase;

/**
 * Whoever makes the quote decides when it becomes a new version. Sending the
 * same version again keeps its number; every send is still kept as the
 * customer got it.
 */
class QuoteVersionsTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use MakesQuotes;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->signIn($this->admin());
    }

    public function test_sending_again_keeps_the_version_number(): void
    {
        $quote = $this->makeQuote();
        $this->addLine($quote);

        $this->sendQuote($quote);
        $this->sendQuote($quote);

        $this->assertSame([1, 1], $quote->versions()->pluck('number')->all());
        $this->assertSame(1, $quote->fresh()->version);
    }

    public function test_a_new_version_starts_only_once_the_version_went_out(): void
    {
        $quote = $this->makeQuote();
        $this->addLine($quote);

        $this->expectException(Refusal::class);
        $this->expectExceptionMessage('nog niet verstuurd');

        app(StartNewQuoteVersionAction::class)->execute($quote);
    }

    public function test_a_new_version_reopens_the_quote_and_stops_the_old_links(): void
    {
        $quote = $this->makeQuote();
        $this->addLine($quote);
        $old_link = $this->sendQuote($quote);

        $this->post("/quotes/{$quote->id}/new-version")->assertSessionHasNoErrors();

        $quote->refresh();
        $this->assertSame(2, $quote->version);
        $this->assertSame(QuoteStatuses::concept->value, $quote->status);
        $this->get($old_link)->assertNotFound();

        $this->sendQuote($quote);
        $this->assertSame([2, 1], $quote->versions()->pluck('number')->all());
    }

    public function test_an_accepted_quote_gets_no_new_version(): void
    {
        $quote = $this->makeQuote();
        $this->addLine($quote);
        $this->sendQuote($quote);
        $this->post("/quotes/{$quote->id}/accept", ['name' => 'Jan', 'signature' => $this->signature()])
            ->assertSessionHasNoErrors();

        $this->post("/quotes/{$quote->id}/new-version")->assertSessionHas('error');

        $this->assertSame(1, $quote->fresh()->version);
    }

    public function test_each_send_keeps_what_the_customer_was_shown(): void
    {
        $quote = $this->makeQuote();
        $line = $this->addLine($quote, 100);
        $this->sendQuote($quote);

        $this->post("/quotes/{$quote->id}/new-version");
        $this->patch("/quotes/{$quote->id}/lines/{$line->id}", ['unit_price' => 250]);
        $link = $this->sendQuote($quote);

        $first = $quote->versions()->where('number', 1)->first();
        $this->get("/quotes/{$quote->id}/versions/{$first->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('totals.net', 100)->where('quote.version', 1));

        $this->signOut();
        $this->get($link)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('totals.net', 250)->where('quote.version', 2));
    }
}

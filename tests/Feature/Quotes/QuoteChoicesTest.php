<?php

namespace Tests\Feature\Quotes;

use App\Actions\Quotes\AddQuoteLineAction;
use App\Actions\Quotes\RemoveQuoteLineAction;
use App\Actions\Quotes\ToggleQuoteAlternativeAction;
use App\Actions\Quotes\UpdateQuoteLineAction;
use App\Enums\QuoteLineKinds;
use App\Exceptions\Refusal;
use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\Concerns\MakesQuotes;
use Tests\TestCase;

/**
 * What counts in the total: every ordinary line, an option only when it is
 * taken, of a set of alternatives exactly one, and stelposten along with the
 * rest while they are also summed apart.
 */
class QuoteChoicesTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use MakesQuotes;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->signIn($this->admin());
    }

    public function test_an_alternative_joins_the_line_above_and_the_line_above_keeps_counting(): void
    {
        $quote = $this->makeQuote();
        $single = $this->addLine($quote, 1000, 'Single-split');
        $multi = $this->addLine($quote, 1800, 'Multi-split');

        $this->post("/quotes/{$quote->id}/lines/{$multi->id}/alternative")->assertSessionHasNoErrors();

        $single->refresh();
        $multi->refresh();
        $this->assertNotNull($single->choice_group);
        $this->assertSame($single->choice_group, $multi->choice_group);
        $this->assertTrue($single->is_chosen);
        $this->assertFalse($multi->is_chosen);
        $this->assertTotals($quote, net: 100000, optional: 0);
    }

    public function test_picking_another_alternative_lets_go_of_the_first(): void
    {
        [$quote, $single, $multi] = $this->alternatives();

        $this->patch("/quotes/{$quote->id}/lines/{$multi->id}", ['is_chosen' => true])->assertSessionHasNoErrors();

        $this->assertFalse($single->fresh()->is_chosen);
        $this->assertTrue($multi->fresh()->is_chosen);
        $this->assertTotals($quote, net: 180000, optional: 0);
    }

    public function test_the_alternative_that_counts_is_not_unticked_but_replaced(): void
    {
        [, $single] = $this->alternatives();

        $this->expectException(Refusal::class);

        app(UpdateQuoteLineAction::class)->execute($single, ['is_chosen' => false]);
    }

    public function test_an_alternative_is_never_an_option(): void
    {
        [$quote, , $multi] = $this->alternatives();

        $this->patch("/quotes/{$quote->id}/lines/{$multi->id}", ['is_optional' => true])
            ->assertSessionHasErrors('is_optional');
    }

    public function test_a_set_left_with_one_line_is_an_ordinary_line_again(): void
    {
        [$quote, $single, $multi] = $this->alternatives();
        $hybrid = $this->addLine($quote, 2500, 'Hybride');
        app(ToggleQuoteAlternativeAction::class)->execute($hybrid);

        app(UpdateQuoteLineAction::class)->execute($hybrid->fresh(), ['is_chosen' => true]);
        app(RemoveQuoteLineAction::class)->execute($hybrid->fresh());

        $this->assertTrue($single->fresh()->is_chosen, 'With the chosen one gone, the first one counts.');

        app(ToggleQuoteAlternativeAction::class)->execute($multi->fresh());

        $this->assertNull($single->fresh()->choice_group);
        $this->assertNull($multi->fresh()->choice_group);
        $this->assertTotals($quote, net: 280000, optional: 0);
    }

    public function test_only_a_priced_line_below_a_priced_line_becomes_an_alternative(): void
    {
        $quote = $this->makeQuote();
        app(AddQuoteLineAction::class)->execute($quote, QuoteLineKinds::text, ['description' => 'Toelichting']);
        $line = $this->addLine($quote);

        $this->post("/quotes/{$quote->id}/lines/{$line->id}/alternative")->assertSessionHas('error');

        $this->assertNull($line->fresh()->choice_group);
    }

    public function test_options_are_summed_apart_and_stelposten_are_counted_and_summed_apart(): void
    {
        $quote = $this->makeQuote();
        $this->addLine($quote, 1000, 'Unit');
        $this->addLine($quote, 200, 'Condenspomp', ['is_optional' => true]);
        $piping = $this->addLine($quote, 300, 'Leidingwerk');
        $this->patch("/quotes/{$quote->id}/lines/{$piping->id}", ['is_provisional' => true])->assertSessionHasNoErrors();

        $totals = $quote->fresh()->totals();

        $this->assertSame(130000, $totals->net_cents);
        $this->assertSame(20000, $totals->optional_net_cents);
        $this->assertSame(30000, $totals->provisional_net_cents);
    }

    public function test_the_customer_picks_one_alternative_of_a_set_through_the_link(): void
    {
        [$quote, $single, $multi] = $this->alternatives();
        $link = $this->sendQuote($quote);
        $this->signOut();

        $this->post($link . '/akkoord', [
            'name' => 'Jan Klant',
            'signature' => $this->signature(),
            'options' => [$single->id, $multi->id],
        ])->assertSessionHasErrors('options');

        $this->post($link . '/akkoord', [
            'name' => 'Jan Klant',
            'signature' => $this->signature(),
            'options' => [$multi->id],
        ])->assertSessionHasNoErrors();

        $this->assertFalse($single->fresh()->is_chosen);
        $this->assertTrue($multi->fresh()->is_chosen);
        $this->assertSame(180000, $quote->fresh()->totals()->net_cents);
    }

    /** @return array{0: Quote, 1: QuoteLine, 2: QuoteLine} single-split counting, multi-split not */
    private function alternatives(): array
    {
        $quote = $this->makeQuote();
        $single = $this->addLine($quote, 1000, 'Single-split');
        $multi = $this->addLine($quote, 1800, 'Multi-split');
        app(ToggleQuoteAlternativeAction::class)->execute($multi);

        return [$quote, $single->fresh(), $multi->fresh()];
    }

    private function assertTotals(Quote $quote, int $net, int $optional): void
    {
        $totals = $quote->fresh()->totals();

        $this->assertSame($net, $totals->net_cents);
        $this->assertSame($optional, $totals->optional_net_cents);
    }
}

<?php

namespace Tests\Feature\Quotes;

use App\Domain\Quotes\QuoteSettings;
use App\Exceptions\Refusal;
use App\Models\ActivityChange;
use App\Models\Customer;
use App\Models\GeneralSetting;
use App\Models\Quote;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\Concerns\MakesQuotes;
use Tests\TestCase;

/**
 * What a quote says besides its lines: how the price holds, when the work
 * starts, and when the customer pays what. The payment terms add up to the
 * whole, and a private customer is asked no more up front than the settings
 * allow.
 */
class QuoteTermsTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use MakesQuotes;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->signIn($this->admin());
    }

    public function test_a_new_quote_starts_with_the_payment_terms_and_knows_a_private_customer(): void
    {
        $private = $this->makeQuote();
        $business = $this->makeQuote(Customer::factory()->create(['vat_number' => 'NL123456789B01']));

        $this->assertTrue($private->is_consumer);
        $this->assertFalse($business->is_consumer);
        $this->assertSame(QuoteSettings::DEFAULT_PAYMENT_TERMS, $private->payment_terms);
    }

    public function test_the_payment_terms_add_up_to_the_whole(): void
    {
        $quote = $this->makeQuote();

        $this->patch("/quotes/{$quote->id}", ['payment_schedule' => [
            ['description' => 'bij opdracht', 'percent' => 20, 'before_start' => true],
            ['description' => 'na oplevering', 'percent' => 70],
        ]])->assertSessionHasErrors('payment_schedule');

        $this->patch("/quotes/{$quote->id}", ['payment_schedule' => [
            ['description' => 'bij opdracht', 'percent' => 20, 'before_start' => true],
            ['description' => 'na oplevering', 'percent' => 80],
        ]])->assertSessionHasNoErrors();

        $this->assertEquals(
            [
                ['description' => 'bij opdracht', 'percent' => 20.0, 'before_start' => true],
                ['description' => 'na oplevering', 'percent' => 80.0, 'before_start' => false],
            ],
            $quote->fresh()->payment_schedule,
        );
    }

    public function test_a_malformed_schedule_is_answered_with_what_is_missing(): void
    {
        $quote = $this->makeQuote();

        $this->patch("/quotes/{$quote->id}", ['payment_schedule' => [['description' => 'bij opdracht']]])
            ->assertSessionHasErrors('payment_schedule.0.percent');
        $this->patch("/quotes/{$quote->id}", ['payment_schedule' => 'alles vooraf'])
            ->assertSessionHasErrors('payment_schedule');
    }

    public function test_a_private_customer_is_asked_no_more_up_front_than_the_settings_allow(): void
    {
        $quote = $this->makeQuote();
        $schedule = [
            ['description' => 'bij opdracht', 'percent' => 30, 'before_start' => true],
            ['description' => 'na oplevering', 'percent' => 70],
        ];

        $this->patch("/quotes/{$quote->id}", ['payment_schedule' => $schedule])->assertSessionHasErrors('payment_schedule');

        GeneralSetting::set('quote_max_upfront_percent', 35);

        $this->patch("/quotes/{$quote->id}", ['payment_schedule' => $schedule])->assertSessionHasNoErrors();
    }

    public function test_turning_a_business_quote_private_is_checked_against_the_terms_it_has(): void
    {
        $quote = $this->makeQuote(Customer::factory()->create(['vat_number' => 'NL123456789B01']));
        $this->patch("/quotes/{$quote->id}", ['payment_schedule' => [
            ['description' => 'vooraf', 'percent' => 50, 'before_start' => true],
            ['description' => 'na oplevering', 'percent' => 50],
        ]])->assertSessionHasNoErrors();

        $this->patch("/quotes/{$quote->id}", ['is_consumer' => true])->assertSessionHasErrors('is_consumer');

        $this->assertFalse($quote->fresh()->is_consumer);
    }

    public function test_terms_that_do_not_hold_do_not_go_out(): void
    {
        $quote = $this->makeQuote();
        $this->addLine($quote);
        $quote->forceFill(['payment_schedule' => [['description' => 'vooraf', 'percent' => 100, 'before_start' => true]]])->save();

        $this->expectException(Refusal::class);
        $this->expectExceptionMessage('kan zo niet de deur uit');

        $this->sendQuote($quote);
    }

    public function test_the_trail_reads_the_schedule_and_the_columns_as_sentences(): void
    {
        $quote = $this->makeQuote();

        $this->patch("/quotes/{$quote->id}", [
            'payment_schedule' => [
                ['description' => 'bij opdracht', 'percent' => 25, 'before_start' => true],
                ['description' => 'na oplevering', 'percent' => 75],
            ],
            'layout' => ['unit_price' => false, 'line_total' => false, 'incl_vat' => true],
            'pricing_method' => 'Nacalculatie',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            '25% bij opdracht (vóór de start), 75% na oplevering',
            $this->changeLabel($quote, 'payment_schedule'),
        );
        $this->assertSame(
            "aantal en eenheid, korting, foto's van producten; prijzen inclusief btw",
            $this->changeLabel($quote, 'layout'),
        );
        $this->assertSame('Nacalculatie', $this->changeLabel($quote, 'pricing_method'));
    }

    private function changeLabel(Quote $quote, string $field): ?string
    {
        return ActivityChange::query()
            ->whereHas('activity', fn ($activity) => $activity->where('subject_type', Quote::class)->where('subject_id', $quote->id))
            ->where('field', $field)
            ->latest('id')
            ->value('new_label');
    }
}

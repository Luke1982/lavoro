<?php

namespace Tests\Feature\Quotes;

use App\Actions\Quotes\SendQuoteReminderAction;
use App\Domain\Quotes\QuoteSettings;
use App\Mail\QuoteConfirmationMail;
use App\Models\Customer;
use App\Models\Quote;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\Concerns\MakesQuotes;
use Tests\TestCase;

/**
 * Saying yes: the general terms the version went out with are accepted with
 * it, a private customer may ask to start within the 14 days, and the customer
 * gets the signed quote while the quote keeps a copy.
 */
class QuoteAcceptanceTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use MakesQuotes;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        Storage::fake('local');
        $this->signIn($this->admin());
    }

    public function test_the_general_terms_must_be_accepted_when_the_version_has_them(): void
    {
        QuoteSettings::storeTerms(UploadedFile::fake()->create('Voorwaarden 2026.pdf', 10, 'application/pdf'));
        $quote = $this->sentQuote();
        $link = $this->lastLink();
        $this->signOut();

        $this->post($link . '/akkoord', $this->yes())->assertSessionHasErrors('terms_accepted');
        $this->post($link . '/akkoord', $this->yes(['terms_accepted' => true]))->assertSessionHasNoErrors();

        $this->assertSame('Voorwaarden 2026.pdf', $quote->fresh()->accepted_terms_name);
    }

    public function test_terms_uploaded_after_sending_are_not_asked_for(): void
    {
        $quote = $this->sentQuote();
        $link = $this->lastLink();
        QuoteSettings::storeTerms(UploadedFile::fake()->create('Later.pdf', 10, 'application/pdf'));
        $this->signOut();

        $this->post($link . '/akkoord', $this->yes())->assertSessionHasNoErrors();

        $this->assertNull($quote->fresh()->accepted_terms_name);
    }

    public function test_a_private_customer_may_ask_to_start_within_the_cooling_off_period(): void
    {
        $quote = $this->sentQuote();
        $link = $this->lastLink();
        $this->signOut();

        $this->post($link . '/akkoord', $this->yes(['starts_within_cooling_off' => true]))->assertSessionHasNoErrors();

        $quote->refresh();
        $this->assertTrue($quote->starts_within_cooling_off);
        $this->assertSame(
            $quote->decided_at->copy()->addDays(Quote::COOLING_OFF_DAYS)->toDateString(),
            $quote->coolingOffUntil()->toDateString(),
        );
    }

    public function test_a_business_customer_has_no_cooling_off_period(): void
    {
        $quote = $this->sentQuote(Customer::factory()->create(['email' => 'inkoop@bedrijf.nl', 'vat_number' => 'NL123456789B01']));
        $link = $this->lastLink();
        $this->signOut();

        $this->post($link . '/akkoord', $this->yes(['starts_within_cooling_off' => true]))->assertSessionHasNoErrors();

        $quote->refresh();
        $this->assertNull($quote->starts_within_cooling_off);
        $this->assertNull($quote->coolingOffUntil());
    }

    public function test_the_customer_gets_the_signed_quote_and_the_quote_keeps_a_copy(): void
    {
        QuoteSettings::storeTerms(UploadedFile::fake()->create('Voorwaarden.pdf', 10, 'application/pdf'));
        $quote = $this->sentQuote();
        $link = $this->lastLink();
        $this->signOut();

        $this->post($link . '/akkoord', $this->yes(['terms_accepted' => true]))->assertSessionHasNoErrors();

        $copy = $quote->documents()->sole();
        $this->assertSame('Offerte ' . $quote->number . ' versie 1 getekend.pdf', $copy->name);
        Storage::disk('public')->assertExists($copy->path);
        Mail::assertSent(QuoteConfirmationMail::class, fn (QuoteConfirmationMail $mail) => $mail->hasTo('klant@example.nl')
            && array_column($mail->files, 'name') === [$copy->name, 'Voorwaarden.pdf']);
    }

    public function test_the_reminders_link_accepts_the_send_it_reminds_of(): void
    {
        $quote = $this->sentQuote();
        $send = $quote->versions()->sole();
        app(SendQuoteReminderAction::class)->execute($send);
        $reminder_link = $this->lastLink();
        $this->signOut();

        $this->post($reminder_link . '/akkoord', $this->yes())->assertSessionHasNoErrors();

        $this->assertSame($send->id, $quote->fresh()->accepted_version_id);
        $this->assertSame(1, $quote->versions()->count());
    }

    public function test_the_signed_copy_keeps_the_terms_the_send_went_out_with(): void
    {
        QuoteSettings::storeTerms(UploadedFile::fake()->create('Voorwaarden 2025.pdf', 10, 'application/pdf'));
        $quote = $this->sentQuote();
        $link = $this->lastLink();
        QuoteSettings::storeTerms(UploadedFile::fake()->create('Voorwaarden 2026.pdf', 10, 'application/pdf'));
        $this->signOut();

        $this->post($link . '/akkoord', $this->yes(['terms_accepted' => true]))->assertSessionHasNoErrors();

        $this->assertSame('Voorwaarden 2025.pdf', $quote->fresh()->signedDocument()['terms']['name']);
        Mail::assertSent(QuoteConfirmationMail::class, fn (QuoteConfirmationMail $mail) => array_column($mail->files, 'name')[1] === 'Voorwaarden 2025.pdf');
    }

    public function test_what_was_signed_holds_the_choices_made_with_the_yes(): void
    {
        $quote = $this->makeQuote();
        $this->addLine($quote, 1000, 'Unit');
        $pump = $this->addLine($quote, 200, 'Condenspomp', ['is_optional' => true]);
        $link = $this->sendQuote($quote);
        $this->signOut();

        $this->post($link . '/akkoord', $this->yes(['options' => [$pump->id]]))->assertSessionHasNoErrors();

        $quote->refresh();
        $send = $quote->versions()->sole();
        $this->assertSame(1000, $send->snapshot['totals']['net'], 'What was sent stays as it was sent.');
        $this->assertSame(1200, $quote->signedDocument()['totals']['net']);
        $this->assertSame(1200, $send->toListItem($quote)['net']);
    }

    public function test_one_answer_ends_every_other_link_to_the_quote(): void
    {
        $quote = $this->sentQuote();
        $first = $this->lastLink();
        app(SendQuoteReminderAction::class)->execute($quote->versions()->sole());
        $reminder = $this->lastLink();
        $this->signOut();

        $this->post($first . '/akkoord', $this->yes())->assertSessionHasNoErrors();

        $this->get($reminder)->assertNotFound();
        $this->post($reminder . '/afwijzen', ['name' => 'Jan'])->assertNotFound();
        $this->assertSame('Geaccepteerd', $quote->fresh()->status);
    }

    private function sentQuote(?Customer $customer = null): Quote
    {
        $quote = $this->makeQuote($customer);
        $this->addLine($quote, 1000);
        $this->sendQuote($quote, $customer?->email ?? 'klant@example.nl');

        return $quote;
    }

    /** @return array<string, mixed> */
    private function yes(array $more = []): array
    {
        return ['name' => 'Jan Klant', 'signature' => $this->signature(), ...$more];
    }
}

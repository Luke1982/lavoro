<?php

namespace Tests\Feature\Quotes;

use App\Domain\Quotes\QuoteForCustomer;
use App\Domain\Quotes\QuoteSettings;
use App\Models\QuoteLostReason;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\Concerns\MakesQuotes;
use Tests\TestCase;

/**
 * The settings that hold for every quote, kept by whoever may manage them: a
 * blank follow-up is off, and new general terms never replace the file an
 * earlier send named.
 */
class QuoteSettingsTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use MakesQuotes;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
        $this->signIn($this->admin());
    }

    public function test_the_settings_are_saved_and_a_blank_follow_up_is_off(): void
    {
        $this->patch('/quote-settings', [
            'reminder_days' => null,
            'expiry_notice_days' => 5,
            'hourly_rate' => 65,
            'layout' => ['vat' => true],
        ])->assertSessionHasNoErrors();

        $this->assertNull(QuoteSettings::reminderDays());
        $this->assertSame(5, QuoteSettings::expiryNoticeDays());
        $this->assertSame(65.0, QuoteSettings::hourlyRate());
        $this->assertTrue(QuoteSettings::layout()['vat']);
        $this->assertTrue(QuoteSettings::layout()['line_total']);
    }

    public function test_new_terms_leave_the_file_an_earlier_send_named(): void
    {
        $this->post('/quote-settings/terms', ['terms' => UploadedFile::fake()->create('2025.pdf', 10, 'application/pdf')])
            ->assertSessionHasNoErrors();
        $quote = $this->makeQuote();
        $this->addLine($quote);
        $this->sendQuote($quote);

        $this->post('/quote-settings/terms', ['terms' => UploadedFile::fake()->create('2026.pdf', 10, 'application/pdf')]);

        $sent_terms = $quote->versions()->sole()->document()['terms'];
        $this->assertSame('2025.pdf', $sent_terms['name']);
        Storage::disk('local')->assertExists($sent_terms['path']);
        $this->assertSame('2026.pdf', QuoteForCustomer::of($quote->fresh())['terms']['name']);
    }

    public function test_only_whoever_manages_the_settings_changes_them(): void
    {
        $this->signIn($this->userWithPermissions('quote.read', 'quote.update'));

        $this->getJson('/quote-settings')->assertForbidden();
        $this->patchJson('/quote-settings', ['reminder_days' => 3])->assertForbidden();
        $this->postJson('/quote-settings/reasons', ['name' => 'Levertijd'])->assertForbidden();
        $this->getJson('/quote-terms')->assertNotFound();
        $this->assertSame(QuoteSettings::DEFAULT_REMINDER_DAYS, QuoteSettings::reminderDays());
    }

    public function test_a_reason_is_added_renamed_and_kept_from_the_customer(): void
    {
        $this->post('/quote-settings/reasons', ['name' => 'Levertijd te lang'])->assertSessionHasNoErrors();
        $reason = QuoteLostReason::where('name', 'Levertijd te lang')->sole();

        $this->patch("/quote-settings/reasons/{$reason->id}", ['customer_can_pick' => false])->assertSessionHasNoErrors();
        $this->post('/quote-settings/reasons', ['name' => 'Levertijd te lang'])->assertSessionHasErrors('name');

        $this->assertFalse($reason->fresh()->customer_can_pick);
        $this->assertSame(QuoteLostReason::max('position'), $reason->position);
    }
}

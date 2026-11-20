<?php

namespace Tests\Feature\Quotes;

use App\Domain\Quotes\QuoteMail;
use App\Domain\Quotes\QuoteSettings;
use App\Mail\AccessLinkMail;
use App\Models\GeneralSetting;
use App\Models\QuoteVersion;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\Concerns\MakesQuotes;
use Tests\TestCase;

/**
 * The general terms come one way at a time: an uploaded PDF, a text written in
 * the settings and handed over as a PDF, or a link to the company's own page.
 * Whatever way, the customer accepts them with the yes, and each send keeps
 * them as they were.
 */
class QuoteGeneralTermsTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use MakesQuotes;

    private const URL = 'https://www.koeltechniek.nl/algemene-voorwaarden';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
        $this->signIn($this->admin());
    }

    public function test_written_terms_are_cleaned_handed_over_as_a_pdf_and_kept_as_they_were_sent(): void
    {
        $this->patch('/quote-settings', [
            'terms_kind' => 'text',
            'terms_text' => '<h2>Artikel 1</h2><p>Betaling binnen 14 dagen.</p><script>alert(1)</script>',
        ])->assertSessionHasNoErrors();
        $quote = $this->makeQuote();
        $this->addLine($quote);
        $link = $this->sendQuote($quote);

        $files = Mail::sent(AccessLinkMail::class)->last()->files;
        $this->assertSame('Algemene voorwaarden.pdf', $files[1]['name']);
        $this->assertStringStartsWith('%PDF', $files[1]['data']);
        $this->assertStringNotContainsString('script', QuoteSettings::terms()['html']);

        $this->patch('/quote-settings', ['terms_text' => '<p>Nieuwe voorwaarden.</p>']);

        $this->assertStringContainsString('Artikel 1', $quote->versions()->sole()->document()['terms']['html']);
        $this->signOut();
        $this->get($link . '/voorwaarden')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_terms_on_the_companys_own_page_are_linked_and_still_accepted(): void
    {
        $this->patch('/quote-settings', ['terms_kind' => 'link', 'terms_url' => self::URL])->assertSessionHasNoErrors();
        $quote = $this->makeQuote();
        $this->addLine($quote);

        $this->assertStringContainsString(self::URL, QuoteMail::defaults($quote)['body']);

        $link = $this->sendQuote($quote);
        $this->assertCount(1, Mail::sent(AccessLinkMail::class)->last()->files, 'Only the quote goes along.');

        $this->signOut();
        $this->get($link)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('terms', ['kind' => 'link', 'name' => self::URL, 'url' => self::URL]));
        $this->get($link . '/voorwaarden')->assertRedirect(self::URL);
        $this->post($link . '/akkoord', ['name' => 'Jan', 'signature' => $this->signature()])
            ->assertSessionHasErrors('terms_accepted');
        $this->post($link . '/akkoord', ['name' => 'Jan', 'signature' => $this->signature(), 'terms_accepted' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(self::URL, $quote->fresh()->accepted_terms_name);
    }

    public function test_a_link_is_a_whole_web_address(): void
    {
        $this->patch('/quote-settings', ['terms_url' => 'onze-site/voorwaarden'])->assertSessionHasErrors('terms_url');
        $this->patch('/quote-settings', ['terms_url' => 'javascript:alert(1)'])->assertSessionHasErrors('terms_url');
    }

    public function test_none_sends_without_terms_and_each_way_keeps_what_it_holds(): void
    {
        $this->patch('/quote-settings', ['terms_kind' => 'link', 'terms_url' => self::URL]);
        $this->patch('/quote-settings', ['terms_kind' => null]);

        $this->assertNull(QuoteSettings::terms());

        $this->patch('/quote-settings', ['terms_kind' => 'link']);

        $this->assertSame(self::URL, QuoteSettings::terms()['url']);
    }

    public function test_a_pdf_uploaded_before_there_was_a_choice_still_goes_along(): void
    {
        Storage::disk('local')->put('quote-terms/oud.pdf', '%PDF-1.4');
        GeneralSetting::set('quote_terms_path', 'quote-terms/oud.pdf');
        GeneralSetting::set('quote_terms_name', 'Oud.pdf');

        $this->assertSame(
            ['kind' => 'pdf', 'name' => 'Oud.pdf', 'path' => 'quote-terms/oud.pdf'],
            QuoteSettings::terms(),
        );

        $quote = $this->makeQuote();
        $version = QuoteVersion::create(['quote_id' => $quote->id, 'number' => 1, 'snapshot' => [
            'quote' => ['number' => $quote->number, 'title' => 'Oud'],
            'lines' => [],
            'totals' => ['net' => 0],
            'terms' => ['name' => 'Oud.pdf', 'path' => 'quote-terms/oud.pdf'],
        ]]);

        $this->assertSame('pdf', $version->document()['terms']['kind']);
    }
}

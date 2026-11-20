<?php

namespace Tests\Feature\Quotes;

use App\Jobs\FollowUpQuotesJob;
use App\Mail\AccessLinkMail;
use App\Models\GeneralSetting;
use App\Models\Quote;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\Concerns\MakesQuotes;
use Tests\TestCase;

/**
 * What happens to a quote once it is out: whoever made it hears when the
 * customer first looks, the customer is reminded once when they stay quiet,
 * and the maker hears when it is about to run out.
 */
class QuoteFollowUpTest extends TestCase
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

    public function test_the_first_look_is_kept_and_told_to_the_maker_once(): void
    {
        $quote = $this->sentQuote();
        $link = $this->lastLink();
        $this->signOut();

        $this->get($link)->assertOk();
        $this->get($link)->assertOk();

        $this->assertNotNull($quote->versions()->sole()->viewed_at);
        $this->assertSame(1, $this->notifications('quote.viewed'));
        $this->assertSame(1, $this->trailEntries($quote, 'De klant bekeek'));
    }

    public function test_a_quiet_customer_is_reminded_once_after_the_set_days(): void
    {
        $quote = $this->sentQuote();

        $this->travel(6)->days();
        $this->followUp();
        $this->assertCount(1, Mail::sent(AccessLinkMail::class));

        $this->travel(2)->days();
        $this->followUp();
        $this->followUp();

        $this->assertCount(2, Mail::sent(AccessLinkMail::class));
        $this->assertNotNull($quote->versions()->sole()->reminded_at);
        $this->assertStringStartsWith('Herinnering', Mail::sent(AccessLinkMail::class)->last()->rendered_subject);
    }

    public function test_a_send_the_customer_answered_is_not_reminded_after_it_is_marked_sent_again(): void
    {
        $quote = $this->sentQuote();
        $link = $this->lastLink();
        $this->signOut();
        $this->post($link . '/wijziging', ['name' => 'Jan', 'message' => 'Liever wit'])->assertSessionHasNoErrors();
        $this->signIn($this->maker);
        $this->patch("/quotes/{$quote->id}/status", ['status' => 'Verzonden'])->assertSessionHasNoErrors();

        $this->travel(8)->days();
        $this->followUp();

        $this->assertCount(1, Mail::sent(AccessLinkMail::class));
    }

    public function test_no_reminder_when_the_setting_is_blank(): void
    {
        GeneralSetting::set('quote_reminder_days', '');
        $this->sentQuote();

        $this->travel(30)->days();
        $this->followUp();

        $this->assertCount(1, Mail::sent(AccessLinkMail::class));
    }

    public function test_the_maker_hears_once_that_a_quote_runs_out_and_again_for_a_new_date(): void
    {
        $quote = $this->sentQuote();
        $quote->forceFill(['valid_until' => now()->addDays(2)->toDateString()])->save();

        $this->followUp();
        $this->followUp();
        $this->assertSame(1, $this->notifications('quote.expires_soon'));

        $quote->forceFill(['valid_until' => now()->addDays(3)->toDateString()])->save();
        $this->followUp();

        $this->assertSame(2, $this->notifications('quote.expires_soon'));
    }

    private function sentQuote(): Quote
    {
        $quote = $this->makeQuote();
        $this->addLine($quote);
        $this->sendQuote($quote);

        return $quote;
    }

    /** As the scheduler runs it: nobody logged in, so whoever made the quote is not the one who did it. */
    private function followUp(): void
    {
        $this->signOut();
        app()->call([new FollowUpQuotesJob, 'handle']);
    }

    private function notifications(string $type): int
    {
        return UserNotification::where('user_id', $this->maker->id)->where('type', $type)->count();
    }
}

<?php

namespace Tests\Concerns;

use App\Actions\Quotes\AddQuoteLineAction;
use App\Actions\Quotes\CreateQuoteAction;
use App\Actions\Quotes\SendQuoteAction;
use App\Actions\Quotes\UpdateQuoteLineAction;
use App\Enums\QuoteLineKinds;
use App\Mail\AccessLinkMail;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * Quotes the way the screens make them: through the actions, so the signals,
 * the listeners and the trail all run. Whoever uses this fakes the mail first.
 */
trait MakesQuotes
{
    private ?User $signed_in = null;

    /**
     * Logged in as this person, and still after a job ran. In tests the queue
     * runs inside the request, and every job starts by forgetting who is logged
     * in; in production a worker runs it apart and the person stays logged in.
     */
    protected function signIn(User $user): User
    {
        $this->signed_in = $user;
        $this->actingAs($user);
        Queue::after(function () {
            if ($this->signed_in !== null) {
                Auth::setUser($this->signed_in);
            }
        });

        return $user;
    }

    /** Nobody logged in: the customer behind a link, or the scheduler. */
    protected function signOut(): void
    {
        $this->signed_in = null;
        auth()->logout();
    }

    /** A concept for a customer, private unless the customer says otherwise. */
    protected function makeQuote(?Customer $customer = null, array $attributes = []): Quote
    {
        return app(CreateQuoteAction::class)->execute([
            'title' => 'Airco woonkamer',
            'customer_id' => ($customer ?? Customer::factory()->create([
                'email' => 'klant@example.nl',
                'vat_number' => null,
                'chamber_of_commerce_number' => null,
            ]))->id,
            ...$attributes,
        ]);
    }

    /**
     * A line of its own at the bottom, worth its price excluding VAT. Where it
     * goes and whether it is a stelpost are set afterwards, as on the screen.
     */
    protected function addLine(
        Quote $quote,
        float $price = 100,
        string $description = 'Montage',
        array $data = [],
    ): QuoteLine {
        $afterwards = ['location_note', 'is_provisional'];
        $line = app(AddQuoteLineAction::class)->execute($quote, QuoteLineKinds::free, [
            'description' => $description,
            'unit_price' => $price,
            'quantity' => 1,
            ...Arr::except($data, $afterwards),
        ]);

        return Arr::only($data, $afterwards) === []
            ? $line
            : app(UpdateQuoteLineAction::class)->execute($line, Arr::only($data, $afterwards));
    }

    /** Sends the quote and gives the path of the link the customer got. */
    protected function sendQuote(Quote $quote, string $to = 'klant@example.nl'): string
    {
        app(SendQuoteAction::class)->execute($quote->fresh(), $to, 'Offerte', '<p>Hallo</p>');

        return $this->lastLink();
    }

    /** The path of the link in the last mail with one: a send or a reminder. */
    protected function lastLink(): string
    {
        return parse_url(Mail::sent(AccessLinkMail::class)->last()->link_url, PHP_URL_PATH);
    }

    /** How many entries on this record's timeline say this. */
    protected function trailEntries(Model $record, string $sentence): int
    {
        return DB::table('activities')
            ->join('activityables', 'activityables.activity_id', '=', 'activities.id')
            ->where('activityables.activityable_type', $record::class)
            ->where('activityables.activityable_id', $record->getKey())
            ->where('activities.description', 'like', '%' . $sentence . '%')
            ->count();
    }

    /** A signature as the pad hands it over: one white pixel will do. */
    protected function signature(): string
    {
        return 'data:image/png;base64,'
            . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    }
}

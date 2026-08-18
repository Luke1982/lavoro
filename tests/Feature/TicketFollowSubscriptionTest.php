<?php

namespace Tests\Feature;

use App\Enums\TicketStatusses;
use App\Enums\UserNotificationType;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\NotificationSubscription;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Een abonnement op één storing naast het abonnement op een soort.
 *
 * De twee worden anders afgerekend en dat is het hele punt: op een soort teken je
 * in als je alles van dat soort mag lezen, op één record als je dat record mag
 * zien. Wie een werkbon uitvoert ziet de storingen erop, en moet die dus kunnen
 * volgen zonder het brede ticket.read te hebben.
 */
class TicketFollowSubscriptionTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function ticket(): Ticket
    {
        $asset = Asset::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'product_id' => Product::factory()->create()->id,
        ]);

        return Ticket::factory()->create([
            'asset_id' => $asset->id,
            'status' => TicketStatusses::open->value,
            'priority' => 'Normaal',
        ]);
    }

    private function follow(User $user, Ticket $ticket, ?string $type = null): NotificationSubscription
    {
        return NotificationSubscription::create([
            'user_id' => $user->id,
            'type' => $type,
            'subscribable_type' => Ticket::class,
            'subscribable_id' => $ticket->id,
        ]);
    }

    private function changeStatus(Ticket $ticket, User $actor): void
    {
        $this->actingAs($actor);
        $ticket->update(['status' => TicketStatusses::in_behandeling->value]);
    }

    private function notificationsFor(User $user): int
    {
        return UserNotification::where('user_id', $user->id)
            ->where('type', UserNotificationType::ticket_status_changed->value)
            ->count();
    }

    public function test_a_follower_of_one_ticket_is_told_about_that_ticket_only(): void
    {
        $follower = $this->userWith('ticket.read');
        $followed = $this->ticket();
        $other = $this->ticket();

        $this->follow($follower, $followed);

        $this->changeStatus($followed, $this->admin());
        $this->changeStatus($other, $this->admin());

        $this->assertSame(1, $this->notificationsFor($follower));
        $this->assertSame(
            $followed->id,
            UserNotification::where('user_id', $follower->id)->sole()->notificationable_id,
        );
    }

    public function test_following_needs_sight_of_the_record_and_not_the_blanket_permission(): void
    {
        $ticket = $this->ticket();
        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $ticket->update(['service_order_id' => $order->id]);

        $monteur = User::factory()->create();
        $order->addExecutingUser($monteur->id);

        $this->follow($monteur, $ticket);

        $this->changeStatus($ticket, $this->admin());

        $this->assertSame(1, $this->notificationsFor($monteur));
    }

    public function test_a_follower_who_cannot_see_the_ticket_is_not_told(): void
    {
        $stranger = User::factory()->create();
        $ticket = $this->ticket();

        $this->follow($stranger, $ticket);

        $this->changeStatus($ticket, $this->admin());

        $this->assertSame(0, $this->notificationsFor($stranger));
    }

    public function test_a_type_subscriber_is_told_about_every_ticket(): void
    {
        $subscriber = $this->userWith('ticket.read');
        NotificationSubscription::create([
            'user_id' => $subscriber->id,
            'type' => UserNotificationType::ticket_status_changed->value,
        ]);

        $this->changeStatus($this->ticket(), $this->admin());
        $this->changeStatus($this->ticket(), $this->admin());

        $this->assertSame(2, $this->notificationsFor($subscriber));
    }

    public function test_somebody_subscribed_both_ways_is_told_once(): void
    {
        $subscriber = $this->userWith('ticket.read');
        $ticket = $this->ticket();

        NotificationSubscription::create([
            'user_id' => $subscriber->id,
            'type' => UserNotificationType::ticket_status_changed->value,
        ]);
        $this->follow($subscriber, $ticket);

        $this->changeStatus($ticket, $this->admin());

        $this->assertSame(1, $this->notificationsFor($subscriber));
    }

    public function test_the_actor_is_never_told(): void
    {
        $actor = $this->userWithPermissions('ticket.read', 'ticket.change_status');
        $ticket = $this->ticket();

        $this->follow($actor, $ticket);

        $this->changeStatus($ticket, $actor);

        $this->assertSame(0, $this->notificationsFor($actor));
    }

    public function test_the_storing_page_says_whether_you_are_following_it(): void
    {
        $reader = $this->userWith('ticket.read');
        $ticket = $this->ticket();

        $this->actingAs($reader)
            ->get('/tickets/' . $ticket->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('subscriptionId', null));

        $subscription = $this->follow($reader, $ticket);

        $this->actingAs($reader)
            ->get('/tickets/' . $ticket->id)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('subscriptionId', $subscription->id));
    }

    /**
     * De hele ronde zoals de knop hem loopt: kijken, aanzetten, weer kijken,
     * uitzetten, weer kijken. Elke stap moet de volgende mogelijk maken.
     */
    public function test_the_bell_can_be_switched_on_and_off_again(): void
    {
        $reader = $this->userWith('ticket.read');
        $ticket = $this->ticket();
        $page = '/tickets/' . $ticket->id;

        $this->actingAs($reader)->get($page)
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('subscriptionId', null));

        $this->actingAs($reader)->post('/notificationsubscriptions', [
            'subscribable_type' => Ticket::class,
            'subscribable_id' => $ticket->id,
        ])->assertRedirect();

        $subscription = NotificationSubscription::sole();

        $this->actingAs($reader)->get($page)
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->where('subscriptionId', $subscription->id));

        $this->actingAs($reader)
            ->delete('/notificationsubscriptions/' . $subscription->id)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0, NotificationSubscription::count());

        $this->actingAs($reader)->get($page)
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('subscriptionId', null));
    }

    public function test_following_the_same_ticket_twice_is_refused(): void
    {
        $follower = $this->userWith('ticket.read');
        $ticket = $this->ticket();

        $payload = [
            'subscribable_type' => Ticket::class,
            'subscribable_id' => $ticket->id,
        ];

        $this->actingAs($follower)->post('/notificationsubscriptions', $payload)->assertRedirect();
        $this->actingAs($follower)->post('/notificationsubscriptions', $payload)
            ->assertSessionHasErrors('subscribable_id');

        $this->assertSame(1, NotificationSubscription::where('user_id', $follower->id)->count());
    }

    public function test_a_ticket_that_cannot_be_seen_cannot_be_followed(): void
    {
        $stranger = User::factory()->create();
        $ticket = $this->ticket();

        $this->actingAs($stranger)->post('/notificationsubscriptions', [
            'subscribable_type' => Ticket::class,
            'subscribable_id' => $ticket->id,
        ])->assertSessionHasErrors('subscribable_id');

        $this->assertSame(0, NotificationSubscription::count());
    }
}

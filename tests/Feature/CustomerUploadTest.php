<?php

namespace Tests\Feature;

use App\Enums\AccessTokenPurpose;
use App\Enums\TicketStatusses;
use App\Enums\UserNotificationType;
use App\Jobs\DownscaleVideoJob;
use App\Models\AccessToken;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Document;
use App\Models\NotificationSubscription;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * De aanleverpagina zoals een klant hem tegenkomt: geen inlog, één link, en alles
 * wat er binnenkomt komt op de storing terecht.
 */
class CustomerUploadTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $customer = Customer::factory()->create(['name' => 'Van Dijk B.V.']);
        $asset = Asset::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => Product::factory()->create()->id,
            'serial_number' => 'SN-88213',
        ]);

        $this->ticket = Ticket::factory()->create([
            'asset_id' => $asset->id,
            'status' => TicketStatusses::wacht_op_klant->value,
            'priority' => 'Normaal',
        ]);
    }

    private function link(array $requested = ['photos', 'videos']): string
    {
        return AccessToken::issue(
            $this->ticket,
            AccessTokenPurpose::ticket_customer_upload,
            'klant@example.nl',
            ['requested' => $requested],
        )->url();
    }

    public function test_the_page_names_the_machine_and_what_was_asked_for(): void
    {
        $this->get($this->link(['photos', 'other']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/TicketUploadPage')
                ->where('serial', 'SN-88213')
                ->where('closed', false)
                ->has('requested', 2)
                ->where('requested.0', "foto's van de storing")
                ->where('requested.1', 'andere aanvullende informatie'));
    }

    public function test_an_unknown_link_is_not_found(): void
    {
        $this->get('/storing/informatie/dit-bestaat-niet')->assertNotFound();
    }

    public function test_a_revoked_link_is_not_found(): void
    {
        $issued = AccessToken::issue($this->ticket, AccessTokenPurpose::ticket_customer_upload);
        $url = $issued->url();
        $issued->token->revoke();

        $this->get($url)->assertNotFound();
    }

    public function test_a_closed_storing_takes_nothing_more(): void
    {
        $url = $this->link();
        $this->ticket->update(['status' => TicketStatusses::gesloten->value]);

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('closed', true));

        $this->post($url, ['files' => [UploadedFile::fake()->image('foto.jpg')]])
            ->assertSessionHasErrors('files');

        $this->assertSame(0, $this->ticket->images()->count());
    }

    public function test_photos_land_as_images_and_the_rest_as_documents(): void
    {
        Queue::fake();

        $this->post($this->link(), [
            'files' => [
                UploadedFile::fake()->image('storing-1.jpg', 400, 300),
                UploadedFile::fake()->create('filmpje.mp4', 120, 'video/mp4'),
                UploadedFile::fake()->create('handleiding.pdf', 20, 'application/pdf'),
            ],
        ])->assertRedirect();

        $this->assertSame(1, $this->ticket->images()->count());
        $this->assertSame(2, $this->ticket->documents()->count());

        $category = Document::with('category')->first()->category;
        $this->assertSame('Klantinformatie', $category->name);

        Queue::assertPushed(DownscaleVideoJob::class, 1);
    }

    public function test_the_note_lands_as_a_remark_without_a_user(): void
    {
        $this->post($this->link(), ['note' => 'De machine maakt een piepend geluid.'])
            ->assertRedirect();

        $remark = $this->ticket->remarks()->sole();

        $this->assertSame('De machine maakt een piepend geluid.', $remark->content);
        $this->assertNull($remark->user_id);
        $this->assertSame('Van Dijk B.V.', $remark->author_name);
    }

    public function test_an_empty_submission_is_refused(): void
    {
        $this->post($this->link(), [])->assertSessionHasErrors('files');
    }

    public function test_one_submission_writes_one_activity_naming_the_customer(): void
    {
        $this->post($this->link(), [
            'files' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
            ],
            'note' => 'Zie de foto\'s.',
        ])->assertRedirect();

        $activity = Activity::where('event_key', 'ticket.customer_uploaded')->sole();

        $this->assertSame('customer', $activity->actor_type);
        $this->assertSame('Van Dijk B.V. (klant@example.nl)', $activity->actor_name);
        $this->assertNull($activity->user_id);
        $this->assertStringContainsString("2 foto's", $activity->description);
        $this->assertStringContainsString('een toelichting', $activity->description);
    }

    /**
     * De tijdlijn toont een voorbeeldplaatje zodra de melding er een aanwijst. Zonder
     * dit pad blijft dat leeg en is aan de regel niet te zien wat er binnenkwam.
     */
    public function test_the_activity_carries_a_thumbnail_and_reads_as_an_image(): void
    {
        $this->post($this->link(), [
            'files' => [
                UploadedFile::fake()->image('eerste.jpg'),
                UploadedFile::fake()->create('handleiding.pdf', 10, 'application/pdf'),
            ],
        ])->assertRedirect();

        $activity = Activity::where('event_key', 'ticket.customer_uploaded')->sole();

        $this->assertSame('image', $activity->category);
        $this->assertNotNull($activity->metadata['thumbnail_path']);
        $this->assertTrue(Storage::disk('public')->exists($activity->metadata['thumbnail_path']));

        /** Het id gaat mee zodat de tijdlijn straks niet meer van /storage/ afhangt. */
        $this->assertSame(
            $this->ticket->images()->first()->id,
            $activity->metadata['thumbnail_image_id'],
        );
    }

    public function test_an_upload_without_photos_does_not_pretend_to_have_one(): void
    {
        $this->post($this->link(), [
            'files' => [UploadedFile::fake()->create('handleiding.pdf', 10, 'application/pdf')],
        ])->assertRedirect();

        $activity = Activity::where('event_key', 'ticket.customer_uploaded')->sole();

        $this->assertSame('document', $activity->category);
        $this->assertNull($activity->metadata['thumbnail_path']);
    }

    public function test_a_delivery_takes_the_storing_off_waiting(): void
    {
        $this->post($this->link(), ['files' => [UploadedFile::fake()->image('a.jpg')]])
            ->assertRedirect();

        $this->assertSame(TicketStatusses::in_behandeling->value, $this->ticket->fresh()->status);

        $this->assertSame(
            1,
            Activity::where('event_key', 'ticket.status_changed')
                ->where('subject_id', $this->ticket->id)
                ->count(),
        );
    }

    /**
     * Alleen vanuit de wachtfase. Wie de storing intussen ergens anders heeft neergezet
     * weet meer dan deze pagina, en een binnenkomend bestand hoort dat niet terug te
     * draaien.
     */
    public function test_a_delivery_leaves_a_storing_alone_that_is_not_waiting(): void
    {
        $this->ticket->update(['status' => TicketStatusses::open->value]);

        $this->post($this->link(), ['files' => [UploadedFile::fake()->image('a.jpg')]])
            ->assertRedirect();

        $this->assertSame(TicketStatusses::open->value, $this->ticket->fresh()->status);
    }

    public function test_the_followers_of_the_storing_hear_about_it(): void
    {
        $follower = $this->userWith('ticket.read');
        NotificationSubscription::create([
            'user_id' => $follower->id,
            'type' => null,
            'subscribable_type' => Ticket::class,
            'subscribable_id' => $this->ticket->id,
        ]);

        $this->post($this->link(), ['files' => [UploadedFile::fake()->image('a.jpg')]])
            ->assertRedirect();

        $notification = UserNotification::where('user_id', $follower->id)
            ->where('type', UserNotificationType::ticket_customer_uploaded->value)
            ->sole();

        $this->assertStringContainsString('1 foto', $notification->body);
        $this->assertStringContainsString('Van Dijk B.V.', $notification->body);

        /**
         * Twee berichten en niet één: dat de klant iets stuurde en dat de storing
         * daardoor niet meer wacht zijn twee feiten met elk hun eigen intekenaars.
         * Wie alleen op statuswijzigingen intekent hoort het tweede te horen, ook
         * als hij niets van aanleveringen wil weten.
         */
        $this->assertSame(2, UserNotification::where('user_id', $follower->id)->count());
    }

    public function test_the_link_remembers_what_it_already_sent(): void
    {
        $url = $this->link();

        $this->post($url, ['files' => [UploadedFile::fake()->image('eerste.jpg')]])->assertRedirect();

        $token = AccessToken::sole();
        $this->assertSame(1, $token->fresh()->use_count);

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('uploaded', 1)
                ->where('uploaded.0.name', 'eerste.jpg'));
    }

    public function test_more_files_than_allowed_are_refused(): void
    {
        config(['customerupload.max_files' => 2]);

        $this->post($this->link(), [
            'files' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
                UploadedFile::fake()->image('c.jpg'),
            ],
        ])->assertSessionHasErrors('files');

        $this->assertSame(0, $this->ticket->images()->count());
    }
}

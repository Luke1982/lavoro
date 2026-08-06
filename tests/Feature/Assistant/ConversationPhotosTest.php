<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\Contracts\Attachment;
use App\Domain\Assistant\ConversationPhotos;
use App\Models\Asset;
use App\Models\AssistantConversationFact;
use App\Models\AssistantQuestion;
use App\Models\Customer;
use App\Models\Image;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Photos parked with a conversation, kept or thrown away by the person whose
 * storage it is. Nothing lands in their storage without them choosing.
 */
class ConversationPhotosTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private const THREAD = '9d2f1a33-5555-4666-8777-888888888888';

    /** A one-pixel PNG. */
    private function pixel(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    }

    private function parked(): void
    {
        app(ConversationPhotos::class)->stash(self::THREAD, [
            new Attachment(name: 'foto-1', media_type: 'image/png', base64: $this->pixel()),
        ]);
    }

    private function owner(): User
    {
        $user = $this->userWith('assistant.use');

        AssistantQuestion::create([
            'user_id' => $user->id,
            'conversation_id' => self::THREAD,
            'question' => 'Wat is dit voor machine?',
            'answer' => 'Een airco.',
        ]);

        return $user;
    }

    public function test_kept_photos_land_on_the_machine_the_conversation_settled(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = $this->owner();
        $machine = Asset::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'product_id' => Product::factory()->create()->id,
        ]);

        AssistantConversationFact::create([
            'user_id' => $user->id,
            'conversation_id' => self::THREAD,
            'facts' => ['machine' => ['id' => $machine->id, 'label' => null]],
        ]);

        $this->parked();

        $response = $this->actingAs($user)->postJson('/assistant/photos/keep', ['conversation' => self::THREAD]);

        $response->assertOk();
        $this->assertStringContainsString('machine #' . $machine->id, $response->json('message'));

        $image = Image::sole();

        $this->assertTrue($machine->images()->whereKey($image->id)->exists(), 'the photo never reached the machine');
        $this->assertTrue(Storage::disk('public')->exists($image->path), 'the file is a row without a file');
        $this->assertEmpty(Storage::disk('local')->allFiles('assistant-photos/' . self::THREAD), 'the parked copy stayed behind');
    }

    /** A machine beats a werkbon as the home; without a machine, the werkbon takes it. */
    public function test_without_a_machine_the_werkbon_takes_the_photos(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = $this->owner();
        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        AssistantConversationFact::create([
            'user_id' => $user->id,
            'conversation_id' => self::THREAD,
            'facts' => ['werkbon' => ['id' => $order->id, 'label' => null]],
        ]);

        $this->parked();

        $response = $this->actingAs($user)->postJson('/assistant/photos/keep', ['conversation' => self::THREAD]);

        $this->assertStringContainsString('werkbon #' . $order->id, $response->json('message'));
        $this->assertTrue($order->images()->exists());
    }

    public function test_a_conversation_that_settled_nothing_cannot_receive_them(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = $this->owner();
        $this->parked();

        $this->actingAs($user)
            ->postJson('/assistant/photos/keep', ['conversation' => self::THREAD])
            ->assertStatus(422);

        $this->assertSame(0, Image::count());
        $this->assertNotEmpty(
            Storage::disk('local')->allFiles('assistant-photos/' . self::THREAD),
            'the photos were thrown away by a failed keep',
        );
    }

    public function test_someone_elses_parked_photos_cannot_be_kept_or_seen(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->owner();
        $this->parked();

        $outsider = $this->userWith('assistant.use');

        $this->actingAs($outsider)
            ->postJson('/assistant/photos/keep', ['conversation' => self::THREAD])
            ->assertStatus(404);

        $this->actingAs($outsider)
            ->deleteJson('/assistant/photos', ['conversation' => self::THREAD])
            ->assertStatus(404);

        $this->assertNotEmpty(Storage::disk('local')->allFiles('assistant-photos/' . self::THREAD));
    }

    public function test_discarding_throws_the_parked_photos_away(): void
    {
        Storage::fake('local');

        $user = $this->owner();
        $this->parked();

        $this->actingAs($user)
            ->deleteJson('/assistant/photos', ['conversation' => self::THREAD])
            ->assertOk();

        $this->assertEmpty(Storage::disk('local')->allFiles('assistant-photos/' . self::THREAD));
        $this->assertSame(0, Image::count());
    }

    /** Undecided photos get days, not months — nobody chose to keep them. */
    public function test_parked_photos_nobody_decided_about_are_pruned(): void
    {
        Storage::fake('local');

        $this->parked();

        $this->assertSame(0, app(ConversationPhotos::class)->pruneOlderThan(7), 'fresh photos were swept');

        $this->travelTo(now()->addDays(9));

        $this->assertSame(1, app(ConversationPhotos::class)->pruneOlderThan(7));
        $this->assertEmpty(Storage::disk('local')->allFiles('assistant-photos/' . self::THREAD));

        $this->travelBack();
    }
}

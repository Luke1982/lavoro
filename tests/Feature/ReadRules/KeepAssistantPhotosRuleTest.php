<?php

namespace Tests\Feature\ReadRules;

use App\Domain\Assistant\Contracts\Attachment;
use App\Domain\Assistant\ConversationPhotos;
use App\Models\AssistantConversationFact;
use App\Models\AssistantQuestion;
use App\Models\Customer;
use App\Models\Image;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Keeping the photos of a conversation puts them on the record it settled, which
 * takes what uploading them there by hand takes (Image::mayUploadOn): the right
 * to upload photos, on a record this person may see.
 */
class KeepAssistantPhotosRuleTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private const THREAD = '7c1e0a22-4444-4555-8666-777777777777';

    private ServiceOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
    }

    private function conversationOf(User $user): void
    {
        AssistantQuestion::create([
            'user_id' => $user->id,
            'conversation_id' => self::THREAD,
            'question' => 'Wat is dit voor machine?',
            'answer' => 'Een airco.',
        ]);
        AssistantConversationFact::create([
            'user_id' => $user->id,
            'conversation_id' => self::THREAD,
            'facts' => ['werkbon' => ['id' => $this->order->id, 'label' => null]],
        ]);
        app(ConversationPhotos::class)->stash(self::THREAD, [new Attachment(
            name: 'foto-1',
            media_type: 'image/png',
            base64: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        )]);
    }

    private function assertRefused(User $user): void
    {
        $this->conversationOf($user);

        $this->actingAs($user)
            ->postJson('/assistant/photos/keep', ['conversation' => self::THREAD])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_starts_with($message, 'Dit gesprek heeft geen machine'));

        $this->assertSame(0, Image::count());
        $this->assertFalse($this->order->images()->exists());
        $this->assertNotEmpty(Storage::disk('local')->allFiles('assistant-photos/' . self::THREAD), 'the parked photos were thrown away');
    }

    public function test_without_the_right_to_upload_photos_nothing_is_kept(): void
    {
        $this->assertRefused($this->userWithPermissions('assistant.use', 'serviceorder.read'));
    }

    public function test_a_werkbon_this_person_may_not_open_takes_none(): void
    {
        $this->assertRefused($this->userWithPermissions('assistant.use', 'image.upload', 'serviceorder.read_own'));
    }
}

<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\Contracts\Attachment;
use App\Domain\Assistant\Contracts\ModelReply;
use App\Domain\Assistant\Contracts\StopReason;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\Contracts\TokenUsage;
use App\Domain\Assistant\Contracts\UserTurn;
use App\Domain\Assistant\ConversationPhotos;
use App\Domain\Assistant\ModelPicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A photo with a question, all the way to the model.
 *
 * The dangerous failure here is silence: an image that is quietly dropped
 * somewhere along the way gets answered in the same confident voice as one that
 * was seen, which is fabrication with extra steps. So the tests are about
 * arrival and refusal, never about best effort.
 */
class AskWithImagesTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    /** A one-pixel PNG: real base64, no weight. */
    private function photo(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    }

    private function seeingPicker(): void
    {
        config(['assistant.providers.anthropic.api_key' => 'test-key']);

        $this->app->bind(ModelPicker::class, fn () => new ModelPicker(
            fn () => new ImageSpyModel
        ));
    }

    public function test_the_photo_actually_reaches_the_model(): void
    {
        $this->seeingPicker();
        ImageSpyModel::$attachments = [];

        $this->actingAs($this->userWith('assistant.use'))->postJson('/assistant/ask', [
            'question' => 'Wat voor apparaat is dit?',
            'images' => [$this->photo()],
        ])->assertOk();

        $this->assertCount(1, ImageSpyModel::$attachments, 'the photo was quietly dropped on the way');
        $this->assertSame('image/png', ImageSpyModel::$attachments[0]->media_type);
        $this->assertStringStartsWith('iVBOR', ImageSpyModel::$attachments[0]->base64);
    }

    public function test_too_many_photos_are_refused(): void
    {
        config(['assistant.max_images' => 2]);

        $this->actingAs($this->userWith('assistant.use'))->postJson('/assistant/ask', [
            'question' => 'Wat is dit?',
            'images' => [$this->photo(), $this->photo(), $this->photo()],
        ])->assertStatus(422)->assertJsonValidationErrors('images');
    }

    public function test_something_that_is_not_a_photo_is_refused(): void
    {
        $this->actingAs($this->userWith('assistant.use'))->postJson('/assistant/ask', [
            'question' => 'Wat is dit?',
            'images' => ['data:application/pdf;base64,JVBERi0='],
        ])->assertStatus(422)->assertJsonValidationErrors('images.0');
    }

    public function test_an_oversized_photo_is_refused_with_a_size_in_the_message(): void
    {
        config(['assistant.max_image_kb' => 1]);

        $this->actingAs($this->userWith('assistant.use'))->postJson('/assistant/ask', [
            'question' => 'Wat is dit?',
            'images' => ['data:image/png;base64,' . str_repeat('A', 2048)],
        ])->assertStatus(422)->assertJsonValidationErrors('images.0');
    }

    /**
     * No provider that can look at a photo means the photo is refused, not
     * quietly dropped: answered blind it would be described anyway.
     */
    public function test_a_photo_with_no_seeing_provider_is_refused_up_front(): void
    {
        config(['assistant.providers' => [
            'deepseek' => ['api_key' => 'x', 'model' => 'deepseek-chat', 'capability' => 6],
        ]]);

        $this->actingAs($this->userWith('assistant.use'))->postJson('/assistant/ask', [
            'question' => 'Wat voor apparaat is dit?',
            'images' => [$this->photo()],
        ])->assertStatus(422);
    }

    /** Photos force a provider whose adapter really renders them. */
    public function test_routing_skips_the_blind_providers_when_a_photo_rides_along(): void
    {
        config(['assistant.providers' => [
            'deepseek' => ['api_key' => 'x', 'model' => 'deepseek-chat', 'capability' => 9],
            'anthropic' => ['api_key' => 'x', 'model' => 'claude-sonnet-5', 'capability' => 10, 'sees_images' => true],
        ]]);

        $picker = new ModelPicker;

        $this->assertSame('deepseek', $picker->providerFor(3), 'without a photo the cheap provider is fine');
        $this->assertSame('anthropic', $picker->providerFor(3, needs_vision: true), 'a photo went to a blind provider');
    }

    /**
     * A conversation that had photos stays with the provider that can see.
     *
     * Rated on its own, the follow-up "kan je niet op internet zoeken?" is an
     * easy question: it went to the cheap blind model, which truthfully answered
     * that it cannot search or see — which reads as the assistant lying about
     * what it can do, one turn after it described the photo.
     */
    public function test_a_follow_up_in_a_photo_conversation_keeps_the_seeing_provider(): void
    {
        Storage::fake('local');

        $conversation = 'aa11bb22-3333-4444-8555-666677778888';

        config(['assistant.providers.anthropic.api_key' => 'test-key']);
        $this->app->bind(ModelPicker::class, fn () => new VisionFlagPicker);

        app(ConversationPhotos::class)->stash($conversation, [
            new Attachment(name: 'foto-1', media_type: 'image/png', base64: $this->pixelData()),
        ]);

        VisionFlagPicker::$wanted = false;

        $this->actingAs($this->userWith('assistant.use'))->postJson('/assistant/ask', [
            'question' => 'Kan je niet op internet zoeken wat het is?',
            'conversation' => $conversation,
        ])->assertOk();

        $this->assertTrue(VisionFlagPicker::$wanted, 'the follow-up drifted to a blind provider');

        /** And a fresh conversation without photos is free to go cheap. */
        VisionFlagPicker::$wanted = true;

        $this->actingAs($this->userWith('assistant.use'))->postJson('/assistant/ask', [
            'question' => 'Wie is klant 12?',
        ])->assertOk();

        $this->assertFalse(VisionFlagPicker::$wanted);
    }

    private function pixelData(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    }

    /**
     * A photo belongs to the conversation, not to the one message it arrived on.
     *
     * "Kijk nog eens" came in with no image attached and was answered honestly —
     * "ik heb geen foto ontvangen" — one turn after the photo had been described,
     * which reads as the assistant losing its mind rather than as plumbing.
     */
    public function test_a_follow_up_sees_the_photo_again_without_resending_it(): void
    {
        Storage::fake('local');
        $this->seeingPicker();

        $conversation = 'cc33dd44-1111-4222-8333-999900001111';
        $user = $this->userWith('assistant.use');

        ImageSpyModel::$attachments = [];

        $this->actingAs($user)->postJson('/assistant/ask', [
            'question' => 'Wat is dit voor apparaat?',
            'conversation' => $conversation,
            'images' => [$this->photo()],
        ])->assertOk();

        $this->assertCount(1, ImageSpyModel::$attachments);

        /** The follow-up carries no image of its own. */
        ImageSpyModel::$attachments = [];

        $this->actingAs($user)->postJson('/assistant/ask', [
            'question' => 'Kijk nog eens goed naar die foto',
            'conversation' => $conversation,
        ])->assertOk();

        $this->assertCount(1, ImageSpyModel::$attachments, 'the follow-up had no photo to look at');

        /**
         * And only then. Sent on every follow-up, six photographs went back up to
         * answer a question that had nothing to do with looking — tens of times
         * what that question is worth.
         */
        ImageSpyModel::$attachments = [];

        $this->actingAs($user)->postJson('/assistant/ask', [
            'question' => 'Wie is de klant van deze werkbon?',
            'conversation' => $conversation,
        ])->assertOk();

        $this->assertSame([], ImageSpyModel::$attachments, 'the photos went up again for nothing');
    }

    /** Once thrown away, they stop following the conversation around. */
    public function test_discarded_photos_do_not_come_back(): void
    {
        Storage::fake('local');
        $this->seeingPicker();

        $conversation = 'cc33dd44-1111-4222-8333-999900002222';
        $user = $this->userWith('assistant.use');

        $this->actingAs($user)->postJson('/assistant/ask', [
            'question' => 'Wat is dit?',
            'conversation' => $conversation,
            'images' => [$this->photo()],
        ])->assertOk();

        $this->actingAs($user)->deleteJson('/assistant/photos', ['conversation' => $conversation])->assertOk();

        ImageSpyModel::$attachments = [];

        $this->actingAs($user)->postJson('/assistant/ask', [
            'question' => 'En nu, kijk nog eens naar die foto',
            'conversation' => $conversation,
        ])->assertOk();

        $this->assertSame([], ImageSpyModel::$attachments, 'a discarded photo came back');
    }
}

/** Keeps what attachments arrived, so arrival can be asserted rather than assumed. */
class ImageSpyModel implements TalksToModel
{
    /** @var array<int, Attachment> */
    public static array $attachments = [];

    public function seesImages(): bool
    {
        return true;
    }

    public function readsDocuments(): bool
    {
        return false;
    }

    public function send(array $turns, array $tools, string $system): ModelReply
    {
        foreach ($turns as $turn) {
            if ($turn instanceof UserTurn && $turn->attachments !== []) {
                self::$attachments = $turn->attachments;
            }
        }

        return new ModelReply(
            texts: ['Een airco.'],
            tool_calls: [],
            usage: new TokenUsage(1, 1, 0, 0),
            stop_reason: StopReason::finished,
            model: 'image-fake',
            raw: null,
        );
    }
}

/** Captures whether vision was demanded of the pick, then answers cheaply. */
class VisionFlagPicker extends ModelPicker
{
    public static bool $wanted = false;

    public function forDifficulty(
        int $difficulty,
        bool $needs_vision = false,
        bool $needs_documents = false,
    ): TalksToModel {
        self::$wanted = $needs_vision;

        return new ImageSpyModel;
    }
}

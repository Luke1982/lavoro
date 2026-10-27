<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\Contracts\Attachment;
use App\Domain\Assistant\Contracts\ModelReply;
use App\Domain\Assistant\Contracts\StopReason;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\Contracts\TokenUsage;
use App\Domain\Assistant\Contracts\UserTurn;
use App\Domain\Assistant\ConversationFiles;
use App\Domain\Assistant\ModelPicker;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A file with a question, all the way to the model.
 *
 * The same dangerous failure the photos have: a datasheet quietly dropped on the
 * way gets answered from memory in the voice of somebody reading it. So these
 * tests are about arrival and refusal, never about best effort.
 */
class AskWithFilesTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    /** The smallest thing that is really a pdf. */
    private function pdf(): array
    {
        return ['name' => 'SRK71-datasheet.pdf', 'data' => 'data:application/pdf;base64,' . base64_encode('%PDF-1.4')];
    }

    private function readingPicker(): void
    {
        config(['assistant.providers.anthropic.api_key' => 'test-key']);

        $this->app->bind(ModelPicker::class, fn () => new ModelPicker(fn () => new FileSpyModel));
    }

    public function test_the_file_actually_reaches_the_model(): void
    {
        $this->readingPicker();
        FileSpyModel::$attachments = [];

        $this->actingAs($this->userWith('assistant.use'))->postJson('/assistant/ask', [
            'question' => 'Wat is het opgenomen vermogen volgens dit document?',
            'documents' => [$this->pdf()],
        ])->assertOk();

        $this->assertCount(1, FileSpyModel::$attachments, 'the file was quietly dropped on the way');
        $this->assertSame('application/pdf', FileSpyModel::$attachments[0]->media_type);
    }

    /** The model is shown the title, and "bestand-1" tells it nothing about what it is holding. */
    public function test_the_file_keeps_the_name_it_arrived_under(): void
    {
        $this->readingPicker();
        FileSpyModel::$attachments = [];

        $this->actingAs($this->userWith('assistant.use'))->postJson('/assistant/ask', [
            'question' => 'Lees dit document',
            'documents' => [$this->pdf()],
        ])->assertOk();

        $this->assertSame('SRK71-datasheet.pdf', FileSpyModel::$attachments[0]->name);
    }

    public function test_a_kind_of_file_nobody_can_read_is_refused(): void
    {
        $this->actingAs($this->userWith('assistant.use'))->postJson('/assistant/ask', [
            'question' => 'Wat staat hierin?',
            'documents' => [[
                'name' => 'offerte.docx',
                'data' => 'data:application/vnd.openxmlformats-officedocument.wordprocessingml.document;base64,UEs=',
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('documents.0.data');
    }

    public function test_too_many_files_are_refused(): void
    {
        config(['assistant.max_documents' => 1]);

        $this->actingAs($this->userWith('assistant.use'))->postJson('/assistant/ask', [
            'question' => 'Vergelijk deze',
            'documents' => [$this->pdf(), $this->pdf()],
        ])->assertStatus(422)->assertJsonValidationErrors('documents');
    }

    public function test_an_oversized_file_is_refused(): void
    {
        config(['assistant.max_document_kb' => 1]);

        $this->actingAs($this->userWith('assistant.use'))->postJson('/assistant/ask', [
            'question' => 'Wat staat hierin?',
            'documents' => [['name' => 'groot.pdf', 'data' => 'data:application/pdf;base64,' . str_repeat('A', 2048)]],
        ])->assertStatus(422)->assertJsonValidationErrors('documents.0.data');
    }

    /**
     * No provider that opens a file means the file is refused, not dropped:
     * answered blind it would be summarised anyway, out of nothing.
     */
    public function test_a_file_with_no_reading_provider_is_refused_up_front(): void
    {
        config(['assistant.providers' => [
            'deepseek' => ['api_key' => 'x', 'model' => 'deepseek-chat', 'capability' => 6],
        ]]);

        $this->actingAs($this->userWith('assistant.use'))->postJson('/assistant/ask', [
            'question' => 'Wat staat er in dit document?',
            'documents' => [$this->pdf()],
        ])->assertStatus(422);
    }

    /** A file forces a provider whose adapter really sends it. */
    public function test_routing_skips_providers_that_cannot_open_a_file(): void
    {
        config(['assistant.providers' => [
            'deepseek' => ['api_key' => 'x', 'model' => 'deepseek-chat', 'capability' => 9],
            'anthropic' => ['api_key' => 'x', 'model' => 'claude-sonnet-5', 'capability' => 10, 'reads_documents' => true],
        ]]);

        $picker = new ModelPicker;

        $this->assertSame('deepseek', $picker->providerFor(3), 'without a file the cheap provider is fine');
        $this->assertSame('anthropic', $picker->providerFor(3, needs_documents: true), 'a file went to a provider that drops it');
    }

    /**
     * A pdf needs a provider that opens files, not one that looks at pictures.
     * Demanding eyes for a datasheet passes over models that would have done.
     */
    public function test_a_file_does_not_demand_a_seeing_provider(): void
    {
        config(['assistant.providers' => [
            'reader' => ['api_key' => 'x', 'model' => 'claude-haiku-4-5-20251001', 'capability' => 9, 'reads_documents' => true],
            'looker' => ['api_key' => 'x', 'model' => 'claude-sonnet-5', 'capability' => 9, 'sees_images' => true],
        ]]);

        $picker = new ModelPicker;

        $this->assertSame('reader', $picker->providerFor(3, needs_documents: true));
        $this->assertSame('looker', $picker->providerFor(3, needs_vision: true));
    }

    /**
     * A file belongs to the conversation, not to the one message it arrived on —
     * and only goes back up when the question is still about it, because a
     * datasheet of twenty pages costs the same again every time it does.
     */
    public function test_a_follow_up_reads_the_file_again_without_resending_it(): void
    {
        Storage::fake('local');
        $this->readingPicker();

        $conversation = 'dd44ee55-1111-4222-8333-999900003333';
        $user = $this->userWith('assistant.use');

        $this->actingAs($user)->postJson('/assistant/ask', [
            'question' => 'Wat staat er in dit document?',
            'conversation' => $conversation,
            'documents' => [$this->pdf()],
        ])->assertOk();

        FileSpyModel::$attachments = [];

        $this->actingAs($user)->postJson('/assistant/ask', [
            'question' => 'En wat zegt het document over het geluidsniveau?',
            'conversation' => $conversation,
        ])->assertOk();

        $this->assertCount(1, FileSpyModel::$attachments, 'the follow-up had no file to read');

        FileSpyModel::$attachments = [];

        $this->actingAs($user)->postJson('/assistant/ask', [
            'question' => 'Wie is de klant van deze werkbon?',
            'conversation' => $conversation,
        ])->assertOk();

        $this->assertSame([], FileSpyModel::$attachments, 'the file went up again for nothing');
    }

    /** Borrowed, never filed: a parked file is not offered a home in somebody's storage. */
    public function test_a_parked_file_is_thrown_away_with_the_photos(): void
    {
        Storage::fake('local');
        $this->readingPicker();

        $conversation = 'dd44ee55-1111-4222-8333-999900004444';
        $user = $this->userWith('assistant.use');

        $this->actingAs($user)->postJson('/assistant/ask', [
            'question' => 'Wat staat er in dit document?',
            'conversation' => $conversation,
            'documents' => [$this->pdf()],
        ])->assertOk();

        $this->assertTrue(app(ConversationFiles::class)->has($conversation));

        $this->actingAs($user)->deleteJson('/assistant/photos', ['conversation' => $conversation])->assertOk();

        $this->assertFalse(app(ConversationFiles::class)->has($conversation), 'a discarded file stayed behind');
    }
}

/** Keeps whatever attachments arrived, so arrival can be asserted rather than assumed. */
class FileSpyModel implements TalksToModel
{
    /** @var array<int, Attachment> */
    public static array $attachments = [];

    public function seesImages(): bool
    {
        return false;
    }

    public function readsDocuments(): bool
    {
        return true;
    }

    public function send(array $turns, array $tools, string $system): ModelReply
    {
        foreach ($turns as $turn) {
            if ($turn instanceof UserTurn && $turn->attachments !== []) {
                self::$attachments = $turn->attachments;
            }
        }

        return new ModelReply(
            texts: ['Zeven kilowatt.'],
            tool_calls: [],
            usage: new TokenUsage(1, 1, 0, 0),
            stop_reason: StopReason::finished,
            model: 'file-fake',
            raw: null,
        );
    }
}

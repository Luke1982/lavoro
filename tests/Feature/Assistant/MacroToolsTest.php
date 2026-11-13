<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\Contracts\Attachment;
use App\Domain\Assistant\ConversationFiles;
use App\Domain\Tools\ConfirmationToken;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use App\Models\Customer;
use App\Models\Remark;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderMacro;
use App\Models\ServiceOrderStage;
use App\Models\ServiceOrderTask;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The assistant reads a macro as the same numbered steps a person sees, and
 * runs it through the same checks as the button on the werkbon: every question
 * on the path answered, the right for every step, and all of it or none.
 */
class MacroToolsTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private ServiceOrder $order;

    private ?string $conversation = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
    }

    protected function tearDown(): void
    {
        if ($this->conversation !== null) {
            app(ConversationFiles::class)->discard($this->conversation);
        }

        parent::tearDown();
    }

    private function macro(array $steps): ServiceOrderMacro
    {
        return ServiceOrderMacro::create(['title' => 'Airco met offerte', 'definition' => ['steps' => $steps]]);
    }

    private function planner(string ...$without): User
    {
        return $this->userWithPermissions(...array_diff([
            'serviceorder.read', 'serviceorder.update', 'serviceordertaskinstance.create', 'servicejob.create', 'document.upload',
        ], $without));
    }

    /** A call as the model makes it: nothing is approved yet. */
    private function ask(string $tool, array $arguments, ?User $user = null): ToolResult
    {
        return app(ToolExecutor::class)->run(new ToolCall($tool, $arguments, $user ?? $this->planner(), conversation: $this->conversation));
    }

    /** The same call after somebody clicked the button. */
    private function carryOut(array $arguments, ?User $user = null): ToolResult
    {
        $user ??= $this->planner();

        return app(ToolExecutor::class)->run(new ToolCall(
            'run_macro',
            $arguments,
            $user,
            confirmation_token: ConfirmationToken::for('run_macro', $arguments, $user, $this->conversation)->encoded(),
        ));
    }

    private function remarks(): array
    {
        return Remark::orderBy('id')->pluck('content')->all();
    }

    private function remarkAndQuestion(): ServiceOrderMacro
    {
        return $this->macro([
            ['id' => 'opmerking', 'type' => 'add_remark', 'text' => ['ask' => 'Wat moet de monteur weten?'], 'internal' => false],
            [
                'id' => 'offerte',
                'type' => 'question',
                'question' => 'Is er een offerte?',
                'yes' => [['id' => 'ja', 'type' => 'add_remark', 'text' => 'Volgens offerte', 'internal' => false]],
                'no' => [['id' => 'nee', 'type' => 'add_remark', 'text' => 'Offerte volgt', 'internal' => false]],
            ],
        ]);
    }

    public function test_the_list_reads_each_macro_as_its_steps_and_what_it_asks(): void
    {
        $macro = $this->macro([
            ['id' => 'opmerking', 'type' => 'add_remark', 'text' => ['ask' => 'Wat moet de monteur weten?'], 'internal' => false],
            [
                'id' => 'offerte',
                'type' => 'question',
                'question' => 'Is er een offerte?',
                'yes' => [['id' => 'ja', 'type' => 'upload_document', 'document_category_id' => null, 'prompt' => 'Upload de offerte', 'internal' => false]],
                'no' => [],
            ],
        ]);

        $result = $this->ask('list_macros', []);

        $this->assertFalse($result->is_error, (string) $result->summary);
        $this->assertSame($macro->id, $result->content['macros'][0]['macro_id']);
        $this->assertSame($macro->readable(), $result->content['macros'][0]['steps']);
        $this->assertSame([
            ['step_id' => 'opmerking', 'field' => 'text', 'question' => 'Wat moet de monteur weten?', 'kind' => 'text'],
            ['step_id' => 'offerte', 'field' => 'answer', 'question' => 'Is er een offerte?', 'kind' => 'yes_no'],
            ['step_id' => 'ja', 'field' => 'file', 'question' => 'Upload de offerte', 'kind' => 'file', 'only_if' => '"Is er een offerte?" = ja'],
        ], $result->content['macros'][0]['asks']);
    }

    public function test_the_list_leaves_out_macros_this_person_cannot_run(): void
    {
        $this->macro([['id' => 'keuringen', 'type' => 'add_service_jobs', 'location' => 'all']]);

        $result = $this->ask('list_macros', [], $this->planner('servicejob.create'));

        $this->assertSame([], $result->content['macros']);
    }

    public function test_a_closed_werkbon_is_refused_before_anybody_is_asked(): void
    {
        $this->order->update(['service_order_stage_id' => ServiceOrderStage::create([
            'name' => 'Gesloten', 'order' => 9, 'is_closed_state' => true,
        ])->id]);
        $macro = $this->macro([['id' => 'opmerking', 'type' => 'add_remark', 'text' => 'Te laat', 'internal' => false]]);

        $result = $this->ask('run_macro', ['service_order_id' => $this->order->id, 'macro_id' => $macro->id]);

        $this->assertTrue($result->is_error);
        $this->assertSame('De werkbon is gesloten.', $result->summary);
    }

    public function test_running_asks_before_it_writes(): void
    {
        $macro = $this->remarkAndQuestion();

        $result = $this->ask('run_macro', [
            'service_order_id' => $this->order->id,
            'macro_id' => $macro->id,
            'answers' => [
                ['step_id' => 'opmerking', 'field' => 'text', 'value' => 'Sleutel bij de buren'],
                ['step_id' => 'offerte', 'field' => 'answer', 'value' => false],
            ],
        ]);

        $this->assertSame('bevestiging_nodig', $result->content['status']);
        $this->assertStringContainsString('Airco met offerte', $result->content['preview']);
        $this->assertStringContainsString('Sleutel bij de buren', $result->content['preview']);
        $this->assertSame([], $this->remarks());
    }

    public function test_the_answers_fill_in_what_the_macro_asks(): void
    {
        $macro = $this->remarkAndQuestion();

        $result = $this->carryOut([
            'service_order_id' => $this->order->id,
            'macro_id' => $macro->id,
            'answers' => [
                ['step_id' => 'opmerking', 'field' => 'text', 'value' => 'Sleutel bij de buren'],
                ['step_id' => 'offerte', 'field' => 'answer', 'value' => false],
            ],
        ]);

        $this->assertFalse($result->is_error, (string) $result->summary);
        $this->assertSame(['Sleutel bij de buren', 'Offerte volgt'], $this->remarks());
    }

    public function test_a_missing_answer_is_refused_before_anybody_is_asked(): void
    {
        $macro = $this->remarkAndQuestion();

        $result = $this->ask('run_macro', [
            'service_order_id' => $this->order->id,
            'macro_id' => $macro->id,
            'answers' => [['step_id' => 'offerte', 'field' => 'answer', 'value' => true]],
        ]);

        $this->assertTrue($result->is_error);
        $this->assertStringContainsString('Wat moet de monteur weten?', (string) $result->summary);
        $this->assertSame([], $this->remarks());
    }

    public function test_a_document_question_takes_a_file_from_the_chat(): void
    {
        $this->conversation = (string) Str::uuid();
        app(ConversationFiles::class)->stash($this->conversation, [new Attachment(
            name: 'Offerte 55420.pdf',
            media_type: 'application/pdf',
            base64: base64_encode("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF"),
        )]);
        $macro = $this->macro([
            ['id' => 'offerte', 'type' => 'upload_document', 'document_category_id' => null, 'prompt' => 'Upload de offerte', 'internal' => false],
        ]);

        $result = $this->carryOut([
            'service_order_id' => $this->order->id,
            'macro_id' => $macro->id,
            'answers' => [['step_id' => 'offerte', 'field' => 'file', 'value' => 'Offerte 55420.pdf']],
        ]);

        $this->assertFalse($result->is_error, (string) $result->summary);
        $document = $this->order->documents()->sole();
        $this->assertSame('Offerte 55420.pdf', $document->name);
        Storage::disk('public')->assertExists($document->path);
    }

    public function test_a_document_question_without_a_file_points_to_the_werkbon(): void
    {
        $macro = $this->macro([
            ['id' => 'offerte', 'type' => 'upload_document', 'document_category_id' => null, 'prompt' => 'Upload de offerte', 'internal' => false],
        ]);

        $result = $this->ask('run_macro', ['service_order_id' => $this->order->id, 'macro_id' => $macro->id]);

        $this->assertTrue($result->is_error);
        $this->assertStringContainsString('Macro uitvoeren', (string) $result->summary);
    }

    public function test_running_needs_the_rights_for_every_step(): void
    {
        $macro = $this->macro([['id' => 'keuringen', 'type' => 'add_service_jobs', 'location' => 'all']]);

        $result = $this->carryOut(
            ['service_order_id' => $this->order->id, 'macro_id' => $macro->id],
            $this->planner('servicejob.create'),
        );

        $this->assertSame(ToolResult::denied()->summary, $result->summary);
        $this->assertSame(0, ServiceJob::count());
    }

    public function test_a_step_that_refuses_says_why_and_keeps_nothing(): void
    {
        $template = ServiceOrderTask::create(['title' => 'Airco installatie']);
        $macro = $this->macro([
            ['id' => 'opmerking', 'type' => 'add_remark', 'text' => 'Eerst dit', 'internal' => false],
            ['id' => 'taken', 'type' => 'add_task', 'service_order_task_id' => $template->id, 'quantity' => 1],
        ]);
        $template->delete();

        $result = $this->carryOut(['service_order_id' => $this->order->id, 'macro_id' => $macro->id]);

        $this->assertTrue($result->is_error);
        $this->assertStringContainsString('pas de macro aan', (string) $result->summary);
        $this->assertSame([], $this->remarks());
    }
}

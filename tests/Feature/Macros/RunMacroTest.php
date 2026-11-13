<?php

namespace Tests\Feature\Macros;

use App\Models\Activity;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\DocumentCategory;
use App\Models\Location;
use App\Models\Product;
use App\Models\Remark;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderMacro;
use App\Models\ServiceOrderStage;
use App\Models\ServiceOrderTask;
use App\Models\ServiceOrderTaskCheck;
use App\Models\ServiceOrderTaskInstance;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A macro runs its steps in order on a werkbon, takes one lane at each yes/no
 * question, fills what it was told to ask from the answers, and does all of it
 * or none of it.
 */
class RunMacroTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private ServiceOrder $order;

    private ServiceOrderTask $template;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $customer = Customer::factory()->create();
        $this->order = ServiceOrder::factory()->create(['customer_id' => $customer->id]);
        $this->product = Product::factory()->create(['bundle' => false, 'registable' => true]);
        $this->template = ServiceOrderTask::create(['title' => 'Airco installatie']);
        $this->template->checks()->attach(ServiceOrderTaskCheck::create(['title' => 'Leidingen afgeperst'])->id, [
            'is_required' => true,
            'sort_order' => 1,
        ]);

        Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => $this->product->id, 'status' => 'Actief']);
    }

    private function macro(array $steps): ServiceOrderMacro
    {
        return ServiceOrderMacro::create(['title' => 'Airco met offerte', 'definition' => ['steps' => $steps]]);
    }

    private function runMacro(ServiceOrderMacro $macro, array $answers = [], ?array $permissions = null)
    {
        $user = $this->userWithPermissions(...($permissions ?? [
            'serviceorder.update', 'serviceordertaskinstance.create', 'servicejob.create', 'document.upload',
        ]));

        return $this->actingAs($user)->post(
            "/serviceorders/{$this->order->id}/macros/{$macro->id}/run",
            ['answers' => $answers],
        );
    }

    private function remarks(): array
    {
        return Remark::orderBy('id')->pluck('content')->all();
    }

    private function addTask(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'taken',
            'type' => 'add_task',
            'service_order_task_id' => $this->template->id,
            'product_id' => $this->product->id,
            'quantity' => 2,
            'checks_per_unit' => true,
        ];
    }

    public function test_the_steps_run_in_order(): void
    {
        $macro = $this->macro([
            $this->addTask(),
            ['id' => 'opmerking', 'type' => 'add_remark', 'text' => 'Werk volgens offerte', 'internal' => true],
            ['id' => 'keuringen', 'type' => 'add_service_jobs', 'location' => 'all'],
        ]);

        $this->runMacro($macro)->assertSessionHasNoErrors()->assertSessionHas('success');

        $task = ServiceOrderTaskInstance::sole();
        $this->assertSame(2, $task->quantity);
        $this->assertSame(2, $task->checks()->count());
        $this->assertSame(['Werk volgens offerte'], $this->order->internalRemarks()->get()->pluck('content')->all());
        $this->assertSame(1, ServiceJob::where('service_order_id', $this->order->id)->count());
    }

    public function test_a_question_takes_one_lane(): void
    {
        $macro = $this->macro([[
            'id' => 'offerte',
            'type' => 'question',
            'question' => 'Is er een offerte?',
            'yes' => [['id' => 'ja', 'type' => 'add_remark', 'text' => 'Volgens offerte', 'internal' => false]],
            'no' => [['id' => 'nee', 'type' => 'add_remark', 'text' => 'Offerte volgt', 'internal' => false]],
        ]]);

        $this->runMacro($macro, ['offerte' => ['answer' => '0']])->assertSessionHasNoErrors();

        $this->assertSame(['Offerte volgt'], $this->remarks());
    }

    public function test_asked_numbers_and_texts_come_from_the_answers(): void
    {
        $macro = $this->macro([
            $this->addTask(['quantity' => ['ask' => "Hoeveel airco's?"]]),
            ['id' => 'opmerking', 'type' => 'add_remark', 'text' => ['ask' => 'Wat moet de monteur weten?'], 'internal' => false],
        ]);

        $this->runMacro($macro, ['taken' => ['quantity' => '4'], 'opmerking' => ['text' => 'Sleutel bij de buren']])->assertSessionHasNoErrors();

        $this->assertSame(4, ServiceOrderTaskInstance::sole()->quantity);
        $this->assertSame(['Sleutel bij de buren'], $this->remarks());
    }

    public function test_a_document_step_files_the_uploaded_file(): void
    {
        $category = DocumentCategory::create(['name' => 'Offerte', 'color' => '#000000', 'order' => 1]);
        $macro = $this->macro([[
            'id' => 'offerte',
            'type' => 'upload_document',
            'document_category_id' => $category->id,
            'prompt' => 'Upload de offerte',
            'internal' => false,
        ]]);

        $this->runMacro($macro, ['offerte' => ['file' => UploadedFile::fake()->create('offerte.pdf', 20, 'application/pdf')]])
            ->assertSessionHasNoErrors();

        $document = $this->order->documents()->sole();
        $this->assertSame($category->id, $document->document_category_id);
        Storage::disk('public')->assertExists($document->path);
    }

    public function test_a_missing_answer_stops_everything(): void
    {
        $macro = $this->macro([
            ['id' => 'opmerking', 'type' => 'add_remark', 'text' => 'Eerst dit', 'internal' => false],
            $this->addTask(['quantity' => ['ask' => "Hoeveel airco's?"]]),
        ]);

        $this->runMacro($macro)->assertSessionHasErrors('answers.taken.quantity');

        $this->assertSame([], $this->remarks());
    }

    public function test_one_failing_step_undoes_the_steps_before_it(): void
    {
        $macro = $this->macro([
            ['id' => 'opmerking', 'type' => 'add_remark', 'text' => 'Eerst dit', 'internal' => false],
            ['id' => 'keuringen', 'type' => 'add_service_jobs', 'location' => 'all'],
        ]);
        ServiceJob::create(['asset_id' => Asset::sole()->id, 'service_order_id' => $this->order->id, 'outcome' => 'Nog geen uitkomst']);

        $this->runMacro($macro)->assertSessionHas('error', 'Er zijn geen machines meer zonder keuring op deze werkbon.');

        $this->assertSame([], $this->remarks());
    }

    public function test_a_macro_pointing_at_something_deleted_runs_nothing(): void
    {
        $macro = $this->macro([
            ['id' => 'opmerking', 'type' => 'add_remark', 'text' => 'Eerst dit', 'internal' => false],
            $this->addTask(),
        ]);
        $this->product->delete();

        $this->runMacro($macro)
            ->assertSessionHas('error', 'Macro "Airco met offerte" verwijst naar iets dat niet meer bestaat; pas de macro aan.');

        $this->assertSame([], $this->remarks());
        $this->assertSame(0, ServiceOrderTaskInstance::count());
    }

    public function test_a_closed_werkbon_runs_no_macro(): void
    {
        $this->order->update(['service_order_stage_id' => ServiceOrderStage::create([
            'name' => 'Gesloten', 'order' => 9, 'is_closed_state' => true,
        ])->id]);
        $macro = $this->macro([['id' => 'opmerking', 'type' => 'add_remark', 'text' => 'Te laat', 'internal' => false]]);

        $this->runMacro($macro)->assertSessionHas('error', 'De werkbon is gesloten.');

        $this->assertSame([], $this->remarks());
    }

    public function test_a_location_answer_is_one_location(): void
    {
        $location = Location::factory()->create(['customer_id' => $this->order->customer_id]);
        $macro = $this->macro([['id' => 'keuringen', 'type' => 'add_service_jobs', 'location' => ['ask' => 'Welke locatie?']]]);

        $this->runMacro($macro, ['keuringen' => ['location' => [$location->id]]])
            ->assertSessionHasErrors('answers.keuringen.location');

        $this->assertSame(0, ServiceJob::count());
    }

    public function test_an_unanswered_question_ends_the_whole_path(): void
    {
        $macro = $this->macro([
            ['id' => 'buiten', 'type' => 'question', 'question' => 'Buiten?', 'no' => [], 'yes' => [
                ['id' => 'binnen', 'type' => 'question', 'question' => 'Binnen?', 'yes' => [], 'no' => []],
            ]],
            ['id' => 'daarna', 'type' => 'add_remark', 'text' => 'Daarna', 'internal' => false],
        ]);

        $path = $macro->steps()->path(['buiten' => ['answer' => '1']]);

        $this->assertSame(['buiten', 'binnen'], array_column($path, 'id'));
    }

    public function test_the_timeline_says_what_was_answered(): void
    {
        $macro = $this->macro([
            $this->addTask(['quantity' => ['ask' => "Hoeveel airco's?"]]),
            ['id' => 'offerte', 'type' => 'question', 'question' => 'Is er een offerte?', 'yes' => [], 'no' => []],
        ]);

        $this->runMacro($macro, ['taken' => ['quantity' => '4'], 'offerte' => ['answer' => '0']])->assertSessionHasNoErrors();

        $this->assertSame(
            'Macro "Airco met offerte" uitgevoerd: Hoeveel airco\'s? → 4; Is er een offerte? → nee',
            Activity::where('description', 'like', 'Macro %')->sole()->description,
        );
    }

    public function test_running_needs_the_rights_for_every_step(): void
    {
        $macro = $this->macro([['id' => 'keuringen', 'type' => 'add_service_jobs', 'location' => 'all']]);

        $this->runMacro($macro, [], ['serviceorder.update'])->assertSessionHas('error');

        $this->assertSame(0, ServiceJob::count());
    }

    public function test_the_macro_reads_as_numbered_sentences(): void
    {
        $macro = $this->macro([
            $this->addTask(['quantity' => ['ask' => "Hoeveel airco's?"]]),
            [
                'id' => 'offerte',
                'type' => 'question',
                'question' => 'Is er een offerte?',
                'yes' => [['id' => 'ja', 'type' => 'upload_document', 'document_category_id' => null, 'prompt' => 'Upload de offerte', 'internal' => false]],
                'no' => [['id' => 'nee', 'type' => 'add_remark', 'text' => 'Offerte volgt', 'internal' => true]],
            ],
            ['id' => 'keuringen', 'type' => 'add_service_jobs', 'location' => ['ask' => 'Welke locatie?']],
        ]);

        $this->assertSame([
            '1. Taak toevoegen: Airco installatie, ' . $this->product->display_name
                . ', aantal gevraagd ("Hoeveel airco\'s?"), checks per machine.',
            '2. Vraag: "Is er een offerte?"',
            '   Als ja:',
            '   2.1. Document uploaden: gevraagd ("Upload de offerte").',
            '   Als nee:',
            '   2.1. Opmerking plaatsen (intern): "Offerte volgt".',
            '3. Keuringen toevoegen: locatie gevraagd ("Welke locatie?").',
        ], $macro->steps()->readable());
    }

    public function test_an_empty_lane_reads_as_nothing(): void
    {
        $macro = $this->macro([['id' => 'offerte', 'type' => 'question', 'question' => 'Offerte?', 'yes' => [], 'no' => [
            ['id' => 'nee', 'type' => 'add_remark', 'text' => 'Offerte volgt', 'internal' => false],
        ]]]);

        $this->assertSame([
            '1. Vraag: "Offerte?"',
            '   Als ja: niets.',
            '   Als nee:',
            '   1.1. Opmerking plaatsen: "Offerte volgt".',
        ], $macro->steps()->readable());
    }

    public function test_checks_per_machine_need_more_than_one_machine(): void
    {
        $this->runMacro($this->macro([$this->addTask(['quantity' => 1])]))->assertSessionHasNoErrors();

        $this->assertFalse(ServiceOrderTaskInstance::sole()->checks_per_unit);
        $this->assertSame(1, ServiceOrderTaskInstance::sole()->checks()->count());
    }
}

<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\Contracts\ModelReply;
use App\Domain\Assistant\Contracts\StopReason;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\Contracts\TokenUsage;
use App\Domain\Assistant\ConversationFacts;
use App\Domain\Assistant\ModelPicker;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use App\Http\Controllers\AssistantController;
use App\Models\AssistantConversationFact;
use App\Models\Customer;
use App\Models\ServiceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * What a conversation has settled, kept rather than remembered.
 *
 * Told a customer had one open werkbon, #4, the assistant later looked up
 * customer #4, found a different company, and planned an installation for them
 * — for the rest of the conversation. Every record here is a bare integer and a
 * transcript never says which table one came from.
 */
class ConversationFactsTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private const THREAD = '3f9a1c52-0000-4000-8000-00000000abcd';

    public function test_a_lookup_that_finds_one_record_settles_it(): void
    {
        $user = $this->userWith('customer.read');
        $customer = Customer::factory()->create(['name' => 'Majorlabel', 'city' => 'Meteren']);
        $facts = app(ConversationFacts::class);

        $facts->learn(self::THREAD, $user, app(ToolExecutor::class)->run(
            new ToolCall('find_customer', ['id' => $customer->id], $user)
        ));

        $settled = $facts->for(self::THREAD, $user);

        $this->assertSame($customer->id, $settled['klant']['id']);
        $this->assertSame('Majorlabel', $settled['klant']['label']);
    }

    /**
     * A list settles nothing. Twenty-five customers is somebody browsing, and
     * writing one of them down as "the customer" would be worse than writing
     * nothing — it would be wrong with the same confidence as being right.
     */
    public function test_a_list_of_matches_settles_nothing(): void
    {
        $user = $this->userWith('customer.read');
        Customer::factory()->count(4)->create(['city' => 'Meteren']);
        $facts = app(ConversationFacts::class);

        $facts->learn(self::THREAD, $user, app(ToolExecutor::class)->run(
            new ToolCall('find_customer', ['city' => 'Meteren'], $user)
        ));

        $this->assertSame([], $facts->for(self::THREAD, $user));
    }

    /**
     * The whole point: the numbers carry their kind. A werkbon and a customer can
     * both be #4, and nothing in "#4" says which.
     */
    public function test_the_sentence_says_which_number_belongs_to_what(): void
    {
        $facts = app(ConversationFacts::class);

        $sentence = $facts->sentence([
            'klant' => ['id' => 2, 'label' => 'Majorlabel'],
            'werkbon' => ['id' => 4, 'label' => null],
        ]);

        $this->assertStringContainsString('klant #2 (Majorlabel)', $sentence);
        $this->assertStringContainsString('werkbon #4', $sentence);
        $this->assertStringContainsString('een werkbonnummer is geen klantnummer', $sentence);
    }

    public function test_nothing_settled_says_nothing_at_all(): void
    {
        $this->assertSame('', app(ConversationFacts::class)->sentence([]));
    }

    /** Kept per conversation and per person, which is what makes it safe to read back. */
    public function test_one_conversation_cannot_read_anothers_notes(): void
    {
        $mine = $this->userWith('customer.read');
        $theirs = $this->userWith('customer.read');
        $customer = Customer::factory()->create(['name' => 'Majorlabel']);
        $facts = app(ConversationFacts::class);

        $found = app(ToolExecutor::class)->run(new ToolCall('find_customer', ['id' => $customer->id], $mine));

        $facts->learn(self::THREAD, $mine, $found);

        $this->assertNotSame([], $facts->for(self::THREAD, $mine));
        $this->assertSame([], $facts->for(self::THREAD, $theirs), 'somebody else read this conversation');
        $this->assertSame([], $facts->for('a-different-thread', $mine));
    }

    /** Somebody correcting themselves is the usual case, so the newest wins. */
    public function test_the_latest_answer_replaces_the_earlier_one(): void
    {
        $user = $this->userWith('customer.read');
        $wrong = Customer::factory()->create(['name' => 'Bouwbedrijf Kreeft']);
        $right = Customer::factory()->create(['name' => 'Majorlabel']);
        $facts = app(ConversationFacts::class);

        foreach ([$wrong, $right] as $customer) {
            $facts->learn(self::THREAD, $user, app(ToolExecutor::class)->run(
                new ToolCall('find_customer', ['id' => $customer->id], $user)
            ));
        }

        $this->assertSame($right->id, $facts->for(self::THREAD, $user)['klant']['id']);
        $this->assertSame(1, AssistantConversationFact::count(), 'a conversation grew a second row of notes');
    }

    /** A failed call establishes nothing, however much it looks like a result. */
    public function test_a_failed_call_settles_nothing(): void
    {
        $user = $this->userWith('customer.read');
        $facts = app(ConversationFacts::class);

        $facts->learn(self::THREAD, $user, ToolResult::failed('Ging niet goed'));

        $this->assertSame([], $facts->for(self::THREAD, $user));
    }

    /** Werkbonnen settle the same way, and keep their own noun. */
    public function test_a_single_werkbon_is_settled_as_a_werkbon(): void
    {
        $user = $this->userWith('serviceorder.read');
        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $facts = app(ConversationFacts::class);

        $facts->learn(self::THREAD, $user, app(ToolExecutor::class)->run(
            new ToolCall('search_service_orders', ['ids' => [$order->id]], $user)
        ));

        $this->assertSame($order->id, $facts->for(self::THREAD, $user)['werkbon']['id']);
    }

    /**
     * Kept notes nobody reads are not notes. This is the wire between storing what
     * a conversation settled and the next question actually being told about it —
     * the join that, missing, makes every part of this pass its own tests while
     * the drift carries on exactly as before.
     */
    public function test_what_was_settled_reaches_the_next_question(): void
    {
        $user = $this->userWith('assistant.use');
        $customer = Customer::factory()->create(['name' => 'Majorlabel', 'city' => 'Meteren']);

        AssistantConversationFact::create([
            'user_id' => $user->id,
            'conversation_id' => self::THREAD,
            'facts' => [
                'klant' => ['id' => $customer->id, 'label' => 'Majorlabel'],
                'werkbon' => ['id' => 4, 'label' => null],
            ],
        ]);

        $context = (new \ReflectionMethod(AssistantController::class, 'context'))
            ->invoke(
                app(AssistantController::class),
                $user,
                '',
                app(ConversationFacts::class)->sentence(
                    app(ConversationFacts::class)->for(self::THREAD, $user)
                ),
            );

        $this->assertStringContainsString('klant #' . $customer->id . ' (Majorlabel)', $context);
        $this->assertStringContainsString('werkbon #4', $context);
        $this->assertStringContainsString('geen klantnummer', $context);
    }

    /**
     * Through the endpoint, because everything above this passes whether or not
     * the controller ever joins the two — which is precisely how a difficulty was
     * once computed and then not handed to the loop.
     */
    public function test_the_endpoint_tells_the_model_what_the_conversation_settled(): void
    {
        FactsSpyModel::$seen = [];

        $this->app->bind(ModelPicker::class, fn () => new ModelPicker(
            fn (int $difficulty) => new FactsSpyModel
        ));

        $user = $this->userWith('assistant.use');

        AssistantConversationFact::create([
            'user_id' => $user->id,
            'conversation_id' => self::THREAD,
            'facts' => ['klant' => ['id' => 2, 'label' => 'Majorlabel']],
        ]);

        $this->actingAs($user)->postJson('/assistant/ask', [
            'question' => 'Plan die installatie in',
            'conversation' => self::THREAD,
        ])->assertOk();

        $this->assertNotEmpty(FactsSpyModel::$seen, 'the fake model was never reached, so this proves nothing');

        $said = implode(' ', FactsSpyModel::$seen);

        $this->assertStringContainsString('klant #2 (Majorlabel)', $said, 'the settled customer never reached the model');
        $this->assertStringContainsString('geen klantnummer', $said);
    }
}

/** Keeps whatever text was sent up, so the context can be read back. */
class FactsSpyModel implements TalksToModel
{
    /**
     * Collected statically. A by-reference promoted constructor property copies
     * rather than aliases, so the caller's array stayed empty and this reported
     * that nothing had reached the model when everything had.
     *
     * @var array<int, string>
     */
    public static array $seen = [];

    public function readsDocuments(): bool
    {
        return false;
    }

    public function send(array $turns, array $tools, string $system): ModelReply
    {
        foreach ($turns as $turn) {
            self::$seen[] = print_r($turn, true);
        }

        return new ModelReply(
            texts: ['Klaar.'],
            tool_calls: [],
            usage: new TokenUsage(1, 1, 0, 0),
            stop_reason: StopReason::finished,
            model: 'facts-fake',
            raw: null,
        );
    }
}

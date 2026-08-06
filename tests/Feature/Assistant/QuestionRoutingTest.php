<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\AssistantLoop;
use App\Domain\Assistant\Contracts\ModelReply;
use App\Domain\Assistant\Contracts\StopReason;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\Contracts\TokenUsage;
use App\Domain\Assistant\ModelPicker;
use App\Domain\Assistant\QuestionSorter;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * That the rating a question is given is the one used to pick a model.
 *
 * The sorting worked, the picking worked, and nothing joined them: the controller
 * computed a difficulty and then called the loop without it, so every question
 * fell back to the ceiling and went to the dearest model. Every part passed its
 * own tests. Nothing tested the wire between them, and the only visible symptom
 * was a usage table where every row said the same thing.
 */
class QuestionRoutingTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    public function test_the_rating_of_the_question_is_what_picks_the_model(): void
    {
        $asked_for = [];

        /** Records what difficulty the loop was asked to buy for. */
        $this->app->bind(ModelPicker::class, function () use (&$asked_for) {
            return new ModelPicker(function (int $difficulty) use (&$asked_for) {
                $asked_for[] = $difficulty;

                return new RoutingModel;
            });
        });

        $this->app->bind(QuestionSorter::class, fn () => new FixedSorter(2));

        $user = $this->userWith('assistant.use');

        $response = $this->actingAs($user)->postJson('/assistant/ask', ['question' => 'Wat is het telefoonnummer van klant 4?']);

        $ceiling = app(ToolRegistry::class)->requiredDifficultyFor($user);

        $response->assertOk();

        $this->assertSame([2], $asked_for, 'the loop was not asked for the rating the question got');
        $this->assertLessThan($ceiling, $asked_for[0], 'an easy question still bought the dearest model');
    }

    /**
     * Never upward past what the person's tools could support, and never sideways
     * into ignoring the rating altogether.
     */
    public function test_a_hard_question_is_capped_by_what_the_tools_can_use(): void
    {
        $asked_for = [];

        $this->app->bind(ModelPicker::class, function () use (&$asked_for) {
            return new ModelPicker(function (int $difficulty) use (&$asked_for) {
                $asked_for[] = $difficulty;

                return new RoutingModel;
            });
        });

        $this->app->bind(QuestionSorter::class, fn () => new FixedSorter(10));

        $user = $this->userWith('assistant.use');

        $this->actingAs($user)->postJson('/assistant/ask', ['question' => 'Waarom lekt deze airco?'])->assertOk();

        $this->assertSame([app(ToolRegistry::class)->requiredDifficultyFor($user)], $asked_for);
    }

    /**
     * Sorting is an economy, never a gate. A sorter that cannot say must leave the
     * question answerable, and err expensive while doing it.
     */
    public function test_a_sorter_with_no_opinion_falls_back_to_the_ceiling(): void
    {
        $asked_for = [];

        $this->app->bind(ModelPicker::class, function () use (&$asked_for) {
            return new ModelPicker(function (int $difficulty) use (&$asked_for) {
                $asked_for[] = $difficulty;

                return new RoutingModel;
            });
        });

        $this->app->bind(QuestionSorter::class, fn () => new FixedSorter(null));

        $user = $this->userWith('assistant.use');

        $this->actingAs($user)->postJson('/assistant/ask', ['question' => 'Iets onduidelijks'])->assertOk();

        $this->assertSame([app(ToolRegistry::class)->requiredDifficultyFor($user)], $asked_for);
    }

    public function test_the_loop_uses_what_it_is_handed_rather_than_working_it_out_again(): void
    {
        $asked_for = [];

        $loop = new AssistantLoop(
            new ModelPicker(function (int $difficulty) use (&$asked_for) {
                $asked_for[] = $difficulty;

                return new RoutingModel;
            }),
            app(ToolRegistry::class),
            app(ToolExecutor::class),
        );

        $loop->ask(user: $this->admin(), question: 'Hallo', system: 'systeem', difficulty: 1);

        $this->assertSame([1], $asked_for);
    }

    /**
     * The command line asks the same questions and must answer them on the same
     * model. It routed by the tool ceiling alone, which made it the one tool
     * anybody would reach for to find out why everything lands on the dear model —
     * confidently reporting a route the application does not take.
     */
    public function test_the_command_line_routes_a_question_the_way_the_box_does(): void
    {
        $user = $this->admin();
        $registry = app(ToolRegistry::class);

        $routed = (new FixedSorter(3))->difficultyFor('Wie is klant 12?', $user, $registry);

        $this->assertSame(3, $routed, 'an easy question was not held down to what it needs');
        $this->assertLessThan(
            $registry->requiredDifficultyFor($user),
            $routed,
            'the ceiling won, so sorting changed nothing',
        );

        /** And a hard one still cannot buy tools the person does not have. */
        $this->assertSame(
            $registry->requiredDifficultyFor($user),
            (new FixedSorter(10))->difficultyFor('Waarom lekt deze airco?', $user, $registry),
        );
    }
}

class FixedSorter extends QuestionSorter
{
    public function __construct(private readonly ?int $says) {}

    public function difficultyOf(string $question, ?User $user = null): ?int
    {
        return $this->says;
    }
}

class RoutingModel implements TalksToModel
{
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
        return new ModelReply(
            texts: ['Klaar.'],
            tool_calls: [],
            usage: new TokenUsage(1, 1, 0, 0),
            stop_reason: StopReason::finished,
            model: 'routing-fake',
            raw: null,
        );
    }
}

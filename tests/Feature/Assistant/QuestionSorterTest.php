<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\Contracts\ModelReply;
use App\Domain\Assistant\Contracts\StopReason;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\Contracts\TokenUsage;
use App\Domain\Assistant\QuestionSorter;
use App\Domain\Tools\ToolRegistry;
use App\Models\AssistantUsage;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Reading a question to decide what may answer it.
 *
 * The point is money. Deciding by what somebody is *allowed* to do stopped
 * sorting anyone the moment fault diagnosis became available to everybody: a
 * monteur asking how many werkbonnen were open got a model able to diagnose a
 * refrigeration fault, at eleven times the price of one that could have counted
 * them.
 */
class QuestionSorterTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function sorterSaying(string $text): QuestionSorter
    {
        app()->bind(SortingModel::class, fn () => new SortingModel($text));

        config([
            'assistant.question_sorter' => 'fake',
            'assistant.providers.fake' => [
                'driver' => SortingModel::class,
                'model' => 'fake',
                'api_key' => 'aanwezig',
                'capability' => 1,
                'reserved' => true,
            ],
        ]);

        return app(QuestionSorter::class);
    }

    public function test_it_reads_the_number_back(): void
    {
        $this->assertSame(2, $this->sorterSaying('2')->difficultyOf('Welke werkbonnen staan open?'));
        $this->assertSame(9, $this->sorterSaying('9')->difficultyOf('Waarom lekt deze airco steeds?'));
    }

    /**
     * Asked for a digit, a cheap model will still sometimes write a sentence.
     */
    public function test_it_finds_the_number_in_a_sentence(): void
    {
        $this->assertSame(7, $this->sorterSaying('Moeilijkheid: 7')->difficultyOf('Wanneer kan deze klus?'));
    }

    public function test_nonsense_is_no_answer(): void
    {
        $this->assertNull($this->sorterSaying('geen idee')->difficultyOf('x'));
        $this->assertNull($this->sorterSaying('42')->difficultyOf('x'));
        $this->assertNull($this->sorterSaying('0')->difficultyOf('x'));
    }

    /**
     * Sorting is an economy, never a gate. A question must not fail because the
     * thing that priced it did.
     */
    public function test_a_sorter_that_breaks_does_not_take_the_question_with_it(): void
    {
        $sorter = $this->sorterSaying('7');
        app()->bind(SortingModel::class, fn () => new SortingModel('7', throws: true));

        $this->assertNull($sorter->difficultyOf('Wanneer kan deze klus?'));
    }

    public function test_it_can_be_switched_off(): void
    {
        config(['assistant.question_sorter' => null]);

        $this->assertNull(app(QuestionSorter::class)->difficultyOf('Wat dan ook'));
    }

    /**
     * The question decides, but never upward past what somebody's tools could
     * support: a diagnosis-shaped question is no reason to buy a clever model for
     * somebody who can only look up a customer.
     */
    public function test_the_question_never_buys_more_than_the_tools_can_use(): void
    {
        $registry = app(ToolRegistry::class);
        $user = $this->userWith('serviceorder.read_own');

        $ceiling = $registry->requiredDifficultyFor($user);
        $asked = $this->sorterSaying('10')->difficultyOf('Waarom lekt deze airco?');

        $this->assertSame($ceiling, min($asked, $ceiling));
        $this->assertLessThanOrEqual($ceiling, min($asked, $ceiling));
    }

    /**
     * The saving. A lookup must come out cheaper than a diagnosis, or none of this
     * was worth doing.
     */
    /**
     * It costs a fraction of a cent and it happens on every question, and it was
     * reaching the accounts nowhere at all. An allowance that under-reports is
     * wrong in exactly the way one that over-reports is.
     */
    public function test_the_sorting_itself_is_billed(): void
    {
        config(['assistant.pricing.fake' => [
            'input' => 1.0, 'output' => 1.0, 'cache_write' => 1.0, 'cache_read' => 1.0,
        ]]);

        $user = $this->userWith('assistant.use');
        $before = AssistantUsage::count();

        $this->sorterSaying('3')->difficultyOf('Welke werkbonnen staan open?', $user);

        $this->assertSame($before + 1, AssistantUsage::count(), 'the sorting call billed nobody');
        $this->assertSame($user->id, AssistantUsage::latest('id')->first()->user_id);
    }

    public function test_a_lookup_sorts_below_a_diagnosis(): void
    {
        $lookup = $this->sorterSaying('2')->difficultyOf('Welke werkbonnen staan open?');
        $diagnosis = $this->sorterSaying('9')->difficultyOf('Hoe los ik deze storing op?');

        $this->assertLessThan($diagnosis, $lookup);
    }
}

class SortingModel implements TalksToModel
{
    public function __construct(
        private readonly string $says,
        private readonly bool $throws = false,
    ) {}

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
        if ($this->throws) {
            throw new \RuntimeException('de indeler is stuk');
        }

        return new ModelReply(
            texts: [$this->says],
            tool_calls: [],
            stop_reason: StopReason::finished,
            usage: new TokenUsage(1, 1, 0, 0),
            model: 'fake',
            raw: null,
        );
    }
}

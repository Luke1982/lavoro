<?php

namespace Tests\Unit;

use App\Domain\Assistant\AssistantSession;
use App\Domain\Assistant\Evals\EvalRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the shared cases in tests/fixtures/assistant-history.json.
 *
 * The chat box builds the history in JavaScript and the eval builds it here.
 * The eval is only worth something while the two send the model the same story.
 */
class AssistantHistorySharedTest extends TestCase
{
    private static function fixture(): array
    {
        return json_decode(
            file_get_contents(dirname(__DIR__) . '/fixtures/assistant-history.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function sharedCases(): array
    {
        $cases = [];

        foreach (self::fixture()['cases'] as $case) {
            $cases[$case['name']] = [$case];
        }

        return $cases;
    }

    public function test_the_limits_are_the_ones_the_box_keeps(): void
    {
        $fixture = self::fixture();

        $this->assertSame($fixture['exchanges'], AssistantSession::REMEMBERED_EXCHANGES);
        $this->assertSame($fixture['answer_chars'], AssistantSession::REMEMBERED_ANSWER_CHARS);
        $this->assertSame($fixture['carried_on'], AssistantSession::CARRIED_ON);
    }

    #[DataProvider('sharedCases')]
    public function test_the_history_matches_the_box(array $case): void
    {
        $this->assertSame($case['expected'], EvalRunner::historyFor($case['exchanges']));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function choiceCases(): array
    {
        $cases = [];

        foreach (self::fixture()['choices'] as $case) {
            $cases[$case['name']] = [$case];
        }

        return $cases;
    }

    #[DataProvider('choiceCases')]
    public function test_a_picked_option_says_what_the_box_sends(array $case): void
    {
        $this->assertSame($case['expected'], EvalRunner::picked($case['choices'], $case['picked']));
    }
}

<?php

namespace App\Domain\Assistant\Evals;

/**
 * One thing a run either did or did not do, with what was found when it did not.
 */
final class Check
{
    public function __construct(
        public readonly string $name,
        public readonly bool $passed,
        public readonly string $detail = '',
    ) {}

    public static function that(string $name, bool $passed, string $detail = ''): self
    {
        return new self($name, $passed, $passed ? '' : $detail);
    }

    /** Added to every run: whatever else was counted, a run that did not finish did not pass. */
    public static function endedCleanly(EvalTranscript $transcript): self
    {
        return self::that('gesprek liep netjes af', $transcript->ending === EvalEnding::user_stopped, $transcript->endingLine());
    }

    public static function noFailedActions(EvalTranscript $transcript): self
    {
        $failed = $transcript->failedActions();

        return self::that(
            'geen mislukte acties',
            $failed === [],
            implode('; ', array_map(fn (array $action) => $action['tool'] . ': ' . $action['summary'], $failed)),
        );
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'passed' => $this->passed, 'detail' => $this->detail];
    }
}

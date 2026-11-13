<?php

namespace App\Domain\Macros;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderMacro;
use App\Models\User;

/**
 * The answers for one run of a macro on one werkbon, checked the same way
 * whether they come from the run window or from the assistant: which steps they
 * lead to, whether this person may do all of those, and what each answer must be.
 */
final class MacroAnswers
{
    /** @var array<int, array<string, mixed>> */
    private readonly array $steps;

    /**
     * @param  array<string, mixed>  $answers  Step id to its answers by field.
     */
    public function __construct(private readonly ServiceOrder $order, ServiceOrderMacro $macro, array $answers)
    {
        $this->steps = $macro->steps()->path($answers);
    }

    public function allows(User $user): bool
    {
        return $user->can('update', $this->order)
            && collect($this->steps)->every(fn (array $step) => MacroSteps::find($step['type'])->allows($user));
    }

    /**
     * What every answer on the path must be, keyed "answers.{step}.{field}".
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_map(fn (array $ask) => $ask['kind']->rules($this->order), $this->asks());
    }

    /**
     * Each answer named by the question it answers.
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        return array_map(fn (array $ask) => '"' . $ask['prompt'] . '"', $this->asks());
    }

    /**
     * What is asked on the path, keyed "answers.{step}.{field}" as the answers are checked.
     *
     * @return array<string, array{prompt: string, kind: AnswerKind}>
     */
    public function asks(): array
    {
        return collect($this->steps)
            ->flatMap(fn (array $step) => collect(MacroDefinition::asks($step))
                ->mapWithKeys(fn (array $ask, string $field) => ['answers.' . $step['id'] . '.' . $field => $ask]))
            ->all();
    }
}

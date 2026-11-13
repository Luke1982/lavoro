<?php

namespace App\Domain\Macros;

use App\Http\Requests\DocumentStoreRequest;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderMacro;
use App\Models\User;
use App\Rules\LocationOfCustomer;
use App\Rules\WithinStorageQuota;

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

    public function allow(User $user): bool
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
        $rules = [];

        foreach ($this->asks() as $key => $ask) {
            $rules[$key] = match ($ask['kind']) {
                'number' => ['required', 'integer', 'min:1', 'max:999'],
                'text' => ['required', 'string', 'max:2000'],
                'yes_no' => ['required', 'boolean'],
                'file' => [
                    'required',
                    'file',
                    'mimes:' . DocumentStoreRequest::ALLOWED_MIMES,
                    'max:102400',
                    new WithinStorageQuota,
                ],
                'location' => ['required', new LocationOfCustomer($this->order->customer_id, or_all: true)],
            };
        }

        return $rules;
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

    /** @return array<string, array{prompt: string, kind: string}> */
    private function asks(): array
    {
        return collect($this->steps)
            ->flatMap(fn (array $step) => collect(MacroDefinition::asks($step))
                ->mapWithKeys(fn (array $ask, string $field) => ['answers.' . $step['id'] . '.' . $field => $ask]))
            ->all();
    }
}

<?php

namespace App\Domain\Macros\Steps;

use App\Domain\Macros\MacroRun;
use App\Domain\Macros\MacroStep;
use App\Models\ServiceOrder;
use App\Models\User;

/** A yes/no question asked when the macro runs; its answer chooses which lane runs. */
class QuestionStep implements MacroStep
{
    public static function type(): string
    {
        return 'question';
    }

    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:255'],
            'yes' => ['present', 'list'],
            'no' => ['present', 'list'],
        ];
    }

    public function askable(): array
    {
        return ['answer' => 'yes_no'];
    }

    public function describe(array $step): string
    {
        return 'Vraag: "' . ($step['question'] ?? '') . '"';
    }

    public function allows(User $user): bool
    {
        return true;
    }

    public function run(ServiceOrder $order, array $step, MacroRun $run): void {}
}

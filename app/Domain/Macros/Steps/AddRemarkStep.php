<?php

namespace App\Domain\Macros\Steps;

use App\Actions\Remarks\AddRemarkAction;
use App\Domain\Macros\Asked;
use App\Domain\Macros\MacroRun;
use App\Domain\Macros\MacroStep;
use App\Models\ServiceOrder;
use App\Models\User;

/** Places a remark on the werkbon, internal or for the customer. */
class AddRemarkStep implements MacroStep
{
    public function __construct(private readonly AddRemarkAction $add) {}

    public static function type(): string
    {
        return 'add_remark';
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:2000'],
            'internal' => ['boolean'],
        ];
    }

    public function askable(): array
    {
        return ['text' => 'text'];
    }

    public function describe(array $step): string
    {
        $text = $step['text'] ?? '';

        return 'Opmerking plaatsen' . (!empty($step['internal']) ? ' (intern)' : '') . ': '
            . (Asked::is($text) ? Asked::quoted($text) : '"' . $text . '"') . '.';
    }

    public function allows(User $user): bool
    {
        return true;
    }

    public function run(ServiceOrder $order, array $step, MacroRun $run): void
    {
        $this->add->execute($order, (string) Asked::value($step, 'text', $run), !empty($step['internal']), $run->user->id);
    }
}

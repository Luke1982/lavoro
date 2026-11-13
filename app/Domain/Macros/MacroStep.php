<?php

namespace App\Domain\Macros;

use App\Models\ServiceOrder;
use App\Models\User;

/**
 * One kind of block in a macro: what it may be set to, what it asks when the macro
 * runs, how it reads as a sentence, who may run it, and what it does.
 */
interface MacroStep
{
    public static function type(): string;

    /**
     * Rules for the block's own settings, by field.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array;

    /**
     * The settings that may also be left to "vragen bij uitvoeren", with what kind of
     * answer they take: number, text, location, file or yes_no.
     *
     * @return array<string, string>
     */
    public function askable(): array;

    public function describe(array $step): string;

    public function allows(User $user): bool;

    public function run(ServiceOrder $order, array $step, MacroRun $run): void;
}

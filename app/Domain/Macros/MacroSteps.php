<?php

namespace App\Domain\Macros;

use App\Domain\Macros\Steps\AddRemarkStep;
use App\Domain\Macros\Steps\AddServiceJobsStep;
use App\Domain\Macros\Steps\AddTaskStep;
use App\Domain\Macros\Steps\QuestionStep;
use App\Domain\Macros\Steps\UploadDocumentStep;

/**
 * Every kind of block a macro can hold, by the type it is stored under.
 */
final class MacroSteps
{
    private const STEPS = [
        AddTaskStep::class,
        QuestionStep::class,
        UploadDocumentStep::class,
        AddRemarkStep::class,
        AddServiceJobsStep::class,
    ];

    public static function find(?string $type): ?MacroStep
    {
        foreach (self::STEPS as $class) {
            if ($class::type() === $type) {
                return app($class);
            }
        }

        return null;
    }
}

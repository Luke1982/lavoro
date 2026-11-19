<?php

namespace App\Rules;

use App\Models\Project;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A werkbon can only be put under a project that is still running. One that is
 * already under a finished project may stay there while the rest of it is edited.
 */
class ProjectAcceptsWork implements ValidationRule
{
    public function __construct(private readonly ?int $current_project_id = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value) || (int) $value === $this->current_project_id) {
            return;
        }

        $refusal = Project::find($value)?->refusesNewWork();

        if ($refusal) {
            $fail($refusal);
        }
    }
}

<?php

namespace App\Http\Requests;

use App\Domain\Macros\MacroAnswers;
use App\Domain\Macros\MacroRun;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Running a macro on a werkbon: the answers to what it asks on the path the
 * yes/no answers choose, and the right to do every step on that path.
 */
class ServiceOrderMacroRunRequest extends FormRequest
{
    private ?MacroAnswers $macro_answers = null;

    public function authorize(): bool
    {
        return $this->user() !== null && $this->answers()->allow($this->user());
    }

    public function rules(): array
    {
        return $this->answers()->rules();
    }

    public function attributes(): array
    {
        return $this->answers()->names();
    }

    public function macroRun(): MacroRun
    {
        return new MacroRun($this->user(), $this->validated('answers', []));
    }

    private function answers(): MacroAnswers
    {
        $given = $this->input('answers');

        return $this->macro_answers ??= new MacroAnswers(
            $this->route('serviceorder'),
            $this->route('serviceordermacro'),
            is_array($given) ? $given : [],
        );
    }
}

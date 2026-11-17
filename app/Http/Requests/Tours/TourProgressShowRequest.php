<?php

namespace App\Http\Requests\Tours;

use App\Domain\Tours\TourCatalog;
use App\Models\TourProgress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TourProgressShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', TourProgress::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['tour' => $this->route('tour')]);
    }

    public function rules(): array
    {
        return [
            'tour' => ['required', 'string', Rule::in(app(TourCatalog::class)->ids())],
        ];
    }
}

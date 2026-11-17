<?php

namespace App\Http\Requests\Tours;

use App\Domain\Tours\TourCatalog;
use App\Models\TourProgress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TourProgressUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('record', TourProgress::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['tour' => $this->route('tour')]);
    }

    public function rules(): array
    {
        $catalog = app(TourCatalog::class);

        return [
            'tour' => ['required', 'string', Rule::in($catalog->ids())],
            'started' => ['sometimes', 'boolean'],
            'seen' => ['sometimes', 'array', 'max:50'],
            'seen.*.chapter' => ['required', 'string', Rule::in($catalog->chapterIds((string) $this->route('tour')))],
            'seen.*.version' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }
}

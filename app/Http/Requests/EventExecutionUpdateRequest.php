<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EventExecutionUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('executeOwn', $this->route('event'));
    }

    public function rules(): array
    {
        return [
            'actual_start' => ['required', 'date'],
            'actual_end' => ['required', 'date', 'after:actual_start'],
            'travel_time_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'actual_breaktime_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'signature_base64' => ['required', 'string'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || $this->isNotFilled('actual_breaktime_minutes')) {
                    return;
                }

                $worked_minutes = $this->date('actual_start')->diffInMinutes($this->date('actual_end'));

                if ($this->integer('actual_breaktime_minutes') > $worked_minutes) {
                    $validator->errors()->add('actual_breaktime_minutes', 'De pauze is langer dan de tijd tussen start en eind.');
                }
            },
        ];
    }
}

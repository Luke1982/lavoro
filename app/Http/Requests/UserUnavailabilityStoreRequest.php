<?php

namespace App\Http\Requests;

use App\Models\UserUnavailability;
use Illuminate\Foundation\Http\FormRequest;

class UserUnavailabilityStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [UserUnavailability::class, $this->route('user')]);
    }

    public function rules(): array
    {
        return [
            'type'           => 'required|in:recurring,holiday',
            'label'          => 'nullable|string|max:255',
            'day_of_week'    => 'required_if:type,recurring|nullable|integer|between:0,6',
            'start_time'     => 'required_if:type,recurring|nullable|date_format:H:i',
            'end_time'       => 'required_if:type,recurring|nullable|date_format:H:i|after:start_time',
            'repeat'         => 'required_if:type,recurring|nullable|in:weekly,biweekly',
            'reference_date' => 'required_if:repeat,biweekly|nullable|date',
            'date'           => 'required_if:type,holiday|nullable|date',
            'end_date'       => 'nullable|date|after_or_equal:date',
        ];
    }
}

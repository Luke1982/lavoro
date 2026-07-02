<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class EventFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && $this->user()->can('provideFeedback', $event);
    }

    public function rules(): array
    {
        return [];
    }
}

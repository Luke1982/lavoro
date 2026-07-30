<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Serves acknowledging and taking that back alike: both ask the same question,
 * which is whether this notification is yours.
 */
class UserNotificationAcknowledgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('usernotification'));
    }

    public function rules(): array
    {
        return [];
    }
}

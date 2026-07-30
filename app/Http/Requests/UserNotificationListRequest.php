<?php

namespace App\Http\Requests;

use App\Models\UserNotification;
use Illuminate\Foundation\Http\FormRequest;

class UserNotificationListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', UserNotification::class);
    }

    public function rules(): array
    {
        return [
            'unread' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}

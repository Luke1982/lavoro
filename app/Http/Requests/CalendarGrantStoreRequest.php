<?php

namespace App\Http\Requests;

use App\Models\CalendarGrant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class CalendarGrantStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::user()->can('manage', CalendarGrant::class);
    }

    public function rules(): array
    {
        return [
            'owner_user_id' => 'required|integer|exists:users,id',
            'viewer_user_id' => 'required|integer|exists:users,id|different:owner_user_id',
        ];
    }
}

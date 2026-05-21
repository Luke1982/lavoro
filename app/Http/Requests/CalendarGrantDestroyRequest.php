<?php

namespace App\Http\Requests;

use App\Models\CalendarGrant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class CalendarGrantDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::user()->can('manage', CalendarGrant::class);
    }

    public function rules(): array
    {
        return [];
    }
}

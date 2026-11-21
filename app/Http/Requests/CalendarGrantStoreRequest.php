<?php

namespace App\Http\Requests;

use App\Models\CalendarGrant;
use App\Models\User;
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
            'owner_user_id' => ['required', 'integer', $this->findableUser()],
            'viewer_user_id' => ['required', 'integer', $this->findableUser(), 'different:owner_user_id'],
        ];
    }

    /**
     * Looked up the way every screen looks users up, so an account the customer
     * cannot see -- MajorLabel's own, or someone who has left -- cannot be named by
     * id either. A plain exists rule reads the table past both.
     */
    private function findableUser(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (!User::query()->whereKey($value)->exists()) {
                $fail('Deze gebruiker bestaat niet.');
            }
        };
    }
}

<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\SeatAvailable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UserRestoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('restore', $this->route('user'));
    }

    public function rules(): array
    {
        return [];
    }

    /**
     * Restoring occupies a seat again.
     *
     * It passes no form at all, so the check that runs along by itself on
     * creating and updating was skipped here: a customer could go over their
     * subscription by deleting someone and bringing them back. The same rule,
     * so there is one count.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $user = $this->route('user');

            if (!$user instanceof User || $user->isSuperAdmin()) {
                return;
            }

            (new SeatAvailable)->validate(
                'seat_type',
                $user->seat_type,
                fn (string $message) => $validator->errors()->add('seat_type', $message),
            );
        });
    }
}

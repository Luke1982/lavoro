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
     * Terugzetten bezet weer een plaats.
     *
     * Het gaat langs geen enkel formulier, dus de controle die bij aanmaken en
     * wijzigen vanzelf meeloopt sloeg hier over: een klant kon over zijn
     * abonnement heen komen door iemand weg te gooien en weer terug te halen.
     * Dezelfde regel, zodat er één telling is.
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

<?php

namespace App\Http\Requests;

use App\Rules\UniqueSerialForProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderTaskInstanceAssetUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();

        if (!$user || !($user->isAdmin()
            || $user->hasPermission('serviceordertaskinstance.open_close')
            || $user->hasPermission('serviceordertaskinstance.update'))) {
            return false;
        }

        return $this->route('asset')->service_order_task_instance_id
            === $this->route('serviceordertaskinstance')->id;
    }

    /**
     * Correcting a mistyped or misread serial is the only edit this route allows —
     * everything else about the machine belongs to the machine's own form.
     */
    public function rules(): array
    {
        return [
            'serial_number' => [
                'required',
                'string',
                'max:255',
                UniqueSerialForProduct::forProduct($this->route('asset')->product_id)
                    ->ignoring($this->route('asset')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'serial_number.required' => 'Serienummer is verplicht.',
        ];
    }
}

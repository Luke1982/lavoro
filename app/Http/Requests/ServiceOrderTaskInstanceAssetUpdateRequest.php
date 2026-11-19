<?php

namespace App\Http\Requests;

use App\Rules\UniqueSerialForProduct;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ServiceOrderTaskInstanceAssetUpdateRequest extends FormRequest
{
    /** The machine has to belong to the task in the address, whoever asks. */
    public function authorize(): Response
    {
        $task = $this->route('serviceordertaskinstance');

        if ($this->route('asset')->service_order_task_instance_id !== $task->id) {
            return Response::deny();
        }

        return Gate::inspect('registerAssets', $task);
    }

    /**
     * Correcting a mistyped or misread serial is the only edit this route allows —
     * everything else about the machine belongs to the machine's own form.
     */
    public function rules(): array
    {
        $asset = $this->route('asset');

        return [
            'serial_number' => [
                $asset->product?->requiresSerial() ? 'required' : 'nullable',
                'string',
                'max:255',
                UniqueSerialForProduct::forProduct($asset->product_id)->ignoring($asset),
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

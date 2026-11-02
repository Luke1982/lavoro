<?php

namespace App\Http\Requests;

use App\Rules\UniqueSerialForProduct;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @method \App\Models\User|null user(string $guard = null)
 */
class ServiceJobAssetSerialUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('servicejob')->asset;

        if ($asset === null || !$asset->product?->requiresSerial()) {
            return false;
        }

        return $this->user()->can('setSerial', $asset);
    }

    /**
     * A refusal here is rendered as a message on the screen, so it says which of
     * the two it is: you may not, or this machine never has one.
     */
    protected function failedAuthorization(): void
    {
        $asset = $this->route('servicejob')->asset;

        throw new AuthorizationException(
            $asset?->product?->requiresSerial()
                ? 'Je mag het serienummer van deze machine niet invullen.'
                : 'Deze machine heeft geen serienummer.'
        );
    }

    /**
     * The serial of the machine being inspected, and nothing else: the rest of
     * that machine's record belongs to the machine's own form.
     */
    public function rules(): array
    {
        $asset = $this->route('servicejob')->asset;

        return [
            'serial_number' => [
                'required',
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

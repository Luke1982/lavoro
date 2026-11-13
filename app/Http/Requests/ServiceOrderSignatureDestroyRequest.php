<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderSignatureDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('clearSignature', $this->route('serviceorder'));
    }

    public function rules(): array
    {
        return [];
    }

    /**
     * Een gesloten werkbon is wat er bij de klant getekend is, en sluiten kan alleen
     * met een handtekening. Wissen kan dus pas als de bon weer open staat.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $serviceorder = $this->route('serviceorder');

            if ($serviceorder->is_closed) {
                $validator->errors()->add(
                    'signature',
                    'De werkbon is gesloten. Heropen hem eerst om de handtekening te wissen.'
                );

                return;
            }

            if (blank($serviceorder->signed_by) && blank($serviceorder->signature_base64)) {
                $validator->errors()->add('signature', 'Deze werkbon is nog niet ondertekend.');
            }
        });
    }
}

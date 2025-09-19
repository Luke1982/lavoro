<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\ServiceOrder;

/**
 * Class ServiceOrderUpdateRequest
 *
 * @property int $customer_id
 * @property string|null $description
 * @property string|null $signed_by
 * @property string|null $signature_base64
 * @property string|null $status
 */
class ServiceOrderUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        $serviceorder = request()->route('serviceorder');
        if ($user && $serviceorder instanceof ServiceOrder) {
            $new = $this->status ?? null;
            $current = $serviceorder->status;
            if ($new === 'closed' && $current !== 'closed') {
                return $user->hasPermission('serviceorder.close');
            }
            if ($new === 'open' && $current !== 'open') {
                return $user->hasPermission('serviceorder.reopen');
            }
        }
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => 'required|exists:customers,id',
            'description' => 'nullable|string|max:1000',
            'closed_on' => 'nullable|date',
            'signed_by' => 'nullable|string|max:100',
            'signature_base64' => 'nullable|string',
            'status' => 'nullable|in:open,closed',
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'Klant is verplicht.',
            'customer_id.exists' => 'De opgegeven klant bestaat niet.',
            'description.max' => 'Beschrijving mag maximaal 1000 tekens bevatten.',
            'closed_on.date' => 'Gesloten op moet een geldige datum zijn.',
            'signed_by.max' => 'Ondertekend door mag maximaal 100 tekens bevatten.',
            'signature_base64.string' => 'Handtekening moet een geldige string zijn.',
            'status.in' => 'Ongeldige status opgegeven.',
        ];
    }
}

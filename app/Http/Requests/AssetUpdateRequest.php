<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AssetUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }
        return $user->isAdmin() || $user->hasPermission('asset.update');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function messages(): array
    {
        return [
            'serial_number.unique' => 'Er bestaat al een machine met dit serienummer voor dit product.',
        ];
    }

    public function rules(): array
    {
        return [
            'product_id' => 'required|exists:products,id',
            'serial_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('assets', 'serial_number')
                    ->ignore(request()->route('asset')?->id)
                    ->where(function ($q) {
                        return $q->where('product_id', request()->input('product_id'));
                    }),
            ],
            'next_service_date' => 'nullable|date',
            'customer_id' => 'required|exists:customers,id',
            'status' => 'required|in:Actief,Niet actief',
        ];
    }
}

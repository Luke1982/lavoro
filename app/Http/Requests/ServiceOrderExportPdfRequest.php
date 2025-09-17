<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderExportPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && $user->hasPermission('serviceorder.export_pdf');
    }

    public function rules(): array
    {
        return [];
    }
}

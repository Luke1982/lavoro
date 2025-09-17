<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderEmailPdfWithChecksRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && $user->hasPermission('serviceorder.email_pdf_with_checks');
    }

    public function rules(): array
    {
        return [];
    }
}

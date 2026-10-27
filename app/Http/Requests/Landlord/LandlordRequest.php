<?php

namespace App\Http\Requests\Landlord;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Everything in the admin panel sits behind the same door: logged in as
 * landlord. Writing that down once saves repeating it in every request, and one
 * cannot be forgotten.
 */
abstract class LandlordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::guard('landlord')->check();
    }
}

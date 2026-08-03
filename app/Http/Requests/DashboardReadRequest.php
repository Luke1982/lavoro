<?php

namespace App\Http\Requests;

use App\Services\DashboardMetrics;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Reading the dashboard itself needs nothing beyond being signed in — every
 * block on it carries its own permission, which the controller applies when it
 * decides whether to gather that block at all.
 *
 * @mixin Request
 *
 * @method array validated()
 */
class DashboardReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', 'string', 'in:' . implode(',', array_keys(DashboardMetrics::PERIODS))],
        ];
    }
}

<?php

namespace App\Http\Requests\Tray;

use App\Models\TrayItem;
use Illuminate\Foundation\Http\FormRequest;

class TrayIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', TrayItem::class);
    }

    public function rules(): array
    {
        return [];
    }
}

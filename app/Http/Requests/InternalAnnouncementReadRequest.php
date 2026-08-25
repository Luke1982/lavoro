<?php

namespace App\Http\Requests;

use App\Models\InternalAnnouncement;
use Illuminate\Foundation\Http\FormRequest;

class InternalAnnouncementReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $announcement = $this->route('internalannouncement');

        return $announcement
            ? $this->user()->can('view', $announcement)
            : $this->user()->can('viewAny', InternalAnnouncement::class);
    }

    public function rules(): array
    {
        return [];
    }
}

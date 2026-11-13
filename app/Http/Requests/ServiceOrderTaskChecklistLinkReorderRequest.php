<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReordersLinks;
use App\Models\ServiceOrderTaskChecklistLink;
use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderTaskChecklistLinkReorderRequest extends FormRequest
{
    use ReordersLinks;

    protected function linkModel(): string
    {
        return ServiceOrderTaskChecklistLink::class;
    }
}

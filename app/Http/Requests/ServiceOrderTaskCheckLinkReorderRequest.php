<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReordersLinks;
use App\Models\ServiceOrderTaskCheckLink;
use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderTaskCheckLinkReorderRequest extends FormRequest
{
    use ReordersLinks;

    protected function linkModel(): string
    {
        return ServiceOrderTaskCheckLink::class;
    }
}

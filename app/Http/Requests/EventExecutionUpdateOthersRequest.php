<?php

namespace App\Http\Requests;

class EventExecutionUpdateOthersRequest extends EventExecutionUpdateRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('executeOthers', [$this->route('event'), (int) $this->route('target_user')->id]);
    }
}

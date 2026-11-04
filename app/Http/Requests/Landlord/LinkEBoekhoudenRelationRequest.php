<?php

namespace App\Http\Requests\Landlord;

class LinkEBoekhoudenRelationRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [
            'code' => 'required|string|max:32',
        ];
    }
}

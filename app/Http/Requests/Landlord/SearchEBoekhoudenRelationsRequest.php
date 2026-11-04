<?php

namespace App\Http\Requests\Landlord;

class SearchEBoekhoudenRelationsRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [
            'term' => 'required|string|min:2|max:100',
        ];
    }
}

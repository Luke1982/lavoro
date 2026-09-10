<?php

namespace App\Http\Requests\Landlord;

/**
 * Taking a failed request out of the list.
 *
 * No rules: there is nothing to validate about a button. A class of its own and
 * not the create one, because that demands a name and an email address which
 * are not sent here -- then a delete button complains about an empty field.
 */
class DestroyProvisioningRequestRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [];
    }
}

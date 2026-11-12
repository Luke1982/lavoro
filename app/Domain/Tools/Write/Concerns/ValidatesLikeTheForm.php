<?php

namespace App\Domain\Tools\Write\Concerns;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Validator;

/**
 * Checking a tool's input with the rules of the screen that makes the same record.
 *
 * A second set of rules beside the form's would be a second opinion on what a
 * valid customer is, and the two would drift: a postal code the form refuses
 * would go in through the assistant, or the other way round. So the tool asks
 * the form's own request, and answers with the form's own Dutch messages.
 */
trait ValidatesLikeTheForm
{
    /**
     * The form request is built around the data rather than instantiated empty,
     * because some rules read the request itself: a location code is unique per
     * customer, and the rule learns which customer by asking the request.
     *
     * @param  class-string<FormRequest>  $request_class
     * @param  array<string, mixed>  $data
     */
    private function asTheFormWould(string $request_class, array $data): Validator
    {
        $request = $request_class::create('/', 'POST', $data);

        return ValidatorFactory::make($data, $request->rules(), $request->messages(), $request->attributes());
    }

    private function complaintOf(Validator $validator): string
    {
        return 'Zo kan het niet worden opgeslagen: ' . implode(' ', $validator->errors()->all());
    }
}

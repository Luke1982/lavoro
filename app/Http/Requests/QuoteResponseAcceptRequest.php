<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesQuoteAcceptance;
use App\Models\AccessToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The customer accepts through the link they were sent. The link is the right:
 * the middleware let only its holder through, for this one quote.
 */
class QuoteResponseAcceptRequest extends FormRequest
{
    use ValidatesQuoteAcceptance;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->acceptanceRules(app(AccessToken::class)->tokenable);
    }

    public function messages(): array
    {
        return $this->acceptanceMessages();
    }
}

<?php

namespace App\Http\Requests;

use App\Domain\Quotes\QuoteOrigins;
use App\Models\Quote;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;

/** Starting a quote from a werkbon or a storing: who may make quotes and may open what it is made from. */
class QuoteOriginReadRequest extends FormRequest
{
    private ServiceOrder|Ticket|null $origin = null;

    public function authorize(): bool
    {
        return $this->user()->can('create', Quote::class) && QuoteOrigins::mayOpen($this->user(), $this->origin());
    }

    public function rules(): array
    {
        return [];
    }

    public function origin(): ServiceOrder|Ticket
    {
        abort_unless(isset(QuoteOrigins::KINDS[$this->route('kind')]), 404);

        return $this->origin ??= QuoteOrigins::find($this->route('kind'), (int) $this->route('id'));
    }
}

<?php

namespace App\Http\Requests;

use App\Enums\QuoteStatuses;
use App\Models\AssistantFilter;
use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;

class QuoteReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $quote = $this->route('quote');

        return $quote
            ? $this->user()->can('view', $quote)
            : $this->user()->can('viewAny', Quote::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'in:' . implode(',', [
                Quote::LIST_OPEN,
                Quote::LIST_EVERY_STATUS,
                ...array_column(QuoteStatuses::cases(), 'value'),
            ])],
            'customer' => ['sometimes', 'nullable', 'integer'],
            'reason' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * The list opens on the open quotes, but with the assistant's filter on every
     * status: the filter was counted over all of them.
     *
     * @return array{search: string, status: string, customer: ?int, reason: ?int}
     */
    public function listQuery(): array
    {
        return [
            'search' => trim((string) $this->validated('search', '')),
            'status' => $this->validated('status') ?? (AssistantFilter::isAsked($this) ? Quote::LIST_EVERY_STATUS : Quote::LIST_OPEN),
            'customer' => $this->validated('customer') ? (int) $this->validated('customer') : null,
            'reason' => $this->validated('reason') ? (int) $this->validated('reason') : null,
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Enums\QuoteStatuses;
use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;

class QuoteReadRequest extends FormRequest
{
    /** The list opens on the quotes still waiting for an answer; 'all' is said out loud. */
    public const OPEN = 'open';

    public const EVERY_STATUS = 'all';

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
                self::OPEN,
                self::EVERY_STATUS,
                ...array_column(QuoteStatuses::cases(), 'value'),
            ])],
            'customer' => ['sometimes', 'nullable', 'integer'],
            'reason' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /** @return array{search: string, status: string, customer: ?int, reason: ?int} */
    public function listQuery(): array
    {
        return [
            'search' => trim((string) $this->validated('search', '')),
            'status' => $this->validated('status') ?? self::OPEN,
            'customer' => $this->validated('customer') ? (int) $this->validated('customer') : null,
            'reason' => $this->validated('reason') ? (int) $this->validated('reason') : null,
        ];
    }
}

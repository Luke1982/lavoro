<?php

namespace App\Domain\Macros;

use App\Http\Requests\DocumentStoreRequest;
use App\Models\Location;
use App\Models\ServiceOrder;
use App\Rules\LocationOfCustomer;
use App\Rules\WithinStorageQuota;
use Illuminate\Http\UploadedFile;

/** The kind of answer a macro asks for when it runs: what the answer must be, and how it reads. */
enum AnswerKind: string
{
    case number = 'number';
    case text = 'text';
    case yes_no = 'yes_no';
    case location = 'location';
    case file = 'file';

    /**
     * What an answer of this kind must be on this werkbon.
     *
     * @return array<int, mixed>
     */
    public function rules(ServiceOrder $order): array
    {
        return ['required', ...match ($this) {
            self::number => ['integer', 'min:1', 'max:999'],
            self::text => ['string', 'max:2000'],
            self::yes_no => ['boolean'],
            self::location => [new LocationOfCustomer($order->customer_id, or_all: true)],
            self::file => [
                'file',
                'mimes:' . DocumentStoreRequest::ALLOWED_MIMES,
                'max:102400',
                new WithinStorageQuota,
            ],
        }];
    }

    /** An answer of this kind in words, for the confirm button and the timeline. */
    public function spoken(mixed $answer): string
    {
        return match ($this) {
            self::yes_no => filter_var($answer, FILTER_VALIDATE_BOOLEAN) ? 'ja' : 'nee',
            self::location => $answer === 'all'
                ? 'alle machines'
                : (Location::find($answer)?->title ?? 'locatie #' . $answer),
            self::file => $answer instanceof UploadedFile ? $answer->getClientOriginalName() : '',
            default => (string) $answer,
        };
    }
}

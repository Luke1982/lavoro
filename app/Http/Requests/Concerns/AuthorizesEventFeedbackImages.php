<?php

namespace App\Http\Requests\Concerns;

use App\Models\Event;
use App\Models\Image;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Photos posted against an event are feedback on it, so they follow the event's
 * provideFeedback ability instead of the image permissions. An existing photo must
 * also hang from that event: otherwise naming any event would open every photo.
 *
 * @mixin FormRequest
 */
trait AuthorizesEventFeedbackImages
{
    protected function isEventFeedback(): bool
    {
        return ltrim((string) $this->input('imageable_type'), '\\') === Event::class;
    }

    protected function authorizeEventFeedback(?Image $image = null): bool
    {
        $event = Event::find($this->integer('imageable_id'));

        return $event !== null
            && $this->user()->can('provideFeedback', $event)
            && ($image === null || $event->images()->whereKey($image->id)->exists());
    }
}

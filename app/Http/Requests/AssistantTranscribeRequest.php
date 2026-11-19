<?php

namespace App\Http\Requests;

use App\Models\Assistant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * A recording to write out for the assistant: spoken into the box, or a voice
 * memo picked from the phone. Browsers record as webm or mp4, phones as m4a.
 */
class AssistantTranscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('use', Assistant::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'audio' => [
                'required', 'file', 'max:' . $this->maxKilobytes(),
                'mimetypes:audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/m4a,audio/aac,audio/wav,audio/x-wav,'
                    . 'audio/webm,audio/ogg,audio/flac,video/webm,video/mp4,application/octet-stream',
            ],
        ];
    }

    /**
     * The configured limit, but never more than this server accepts: above that
     * PHP drops the file before any rule sees it, and the person gets a bare
     * upload error instead of "te groot".
     */
    private function maxKilobytes(): int
    {
        return (int) min(config('assistant.transcription.max_kilobytes'), UploadedFile::getMaxFilesize() / 1024);
    }

    public function messages(): array
    {
        return [
            'audio.max' => 'Die opname is te groot. Neem een kortere op, of spreek hem in delen in.',
            'audio.mimetypes' => 'Dit bestand is geen geluidsopname die de assistent kan gebruiken.',
        ];
    }
}

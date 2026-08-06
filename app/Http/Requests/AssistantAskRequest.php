<?php

namespace App\Http\Requests;

use App\Models\Assistant;
use Illuminate\Foundation\Http\FormRequest;

class AssistantAskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('use', Assistant::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:2', 'max:2000'],
            /**
             * Generous on purpose. A path this does not recognise is ignored, so
             * a long one should cost the question nothing — refusing it would
             * fail the whole ask over a detail that was never needed.
             */
            'page' => ['nullable', 'string', 'max:2048'],
            /** Which thread this belongs to, so the turns can be read back together. */
            'conversation' => ['nullable', 'uuid'],
            'history' => ['nullable', 'array', 'max:6'],
            'history.*.question' => ['required', 'string', 'max:2000'],
            'history.*.answer' => ['required', 'string', 'max:8000'],
            /**
             * Foto's, als data-URLs. Het aantal en de maat zitten in config met
             * voorzichtige standaardwaarden — de limieten van de dev-machine
             * zeggen niets over de server, dus hier wordt niets aangenomen.
             */
            'images' => ['nullable', 'array', 'max:' . (int) config('assistant.max_images', 4)],
            'images.*' => [
                'required',
                'string',
                'regex:#^data:image/(jpeg|png|webp|gif);base64,#',
                'max:' . ((int) config('assistant.max_image_kb', 4000)) * 1024,
            ],
            /**
             * Bestanden, ook als data-URLs. Alleen soorten die de aanbieder echt
             * openslaat: een docx die als document wordt meegestuurd komt terug
             * als onleesbare bytes, en een antwoord daarop leest als een antwoord.
             */
            'documents' => ['nullable', 'array', 'max:' . (int) config('assistant.max_documents', 2)],
            'documents.*.name' => ['required', 'string', 'max:255'],
            'documents.*.data' => [
                'required',
                'string',
                'regex:#^data:(application/pdf|text/plain|text/csv);base64,#',
                'max:' . ((int) config('assistant.max_document_kb', 4000)) * 1024,
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'question.required' => 'Stel een vraag.',
            'question.max' => 'Die vraag is te lang.',
            'images.max' => 'Maximaal ' . (int) config('assistant.max_images', 4) . ' foto\'s per vraag.',
            'images.*.regex' => 'Alleen foto\'s (JPEG, PNG, WebP of GIF) kunnen mee met een vraag.',
            'images.*.max' => 'Die foto is te groot. Maak hem kleiner dan '
                . round((int) config('assistant.max_image_kb', 4000) / 1024, 1) . ' MB.',
            'documents.max' => 'Maximaal ' . (int) config('assistant.max_documents', 2) . ' bestanden per vraag.',
            'documents.*.data.regex' => 'Alleen pdf- en tekstbestanden kunnen mee met een vraag. '
                . 'Een Word- of Excel-bestand kun je als pdf opslaan en zo meesturen.',
            'documents.*.data.max' => 'Dat bestand is te groot. Houd het onder '
                . round((int) config('assistant.max_document_kb', 4000) / 1024, 1) . ' MB.',
        ];
    }
}

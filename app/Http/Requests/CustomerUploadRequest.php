<?php

namespace App\Http\Requests;

use App\Rules\CustomerUploadFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Wat een klant via een aanleverlink instuurt.
 *
 * Er valt hier niemand te machtigen: de link ís de machtiging, en die is al door
 * de middleware nagelopen voordat dit verzoek bestaat. Wat overblijft is de vraag
 * of wat er ingestuurd wordt binnen de grenzen valt.
 */
class CustomerUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'files' => ['nullable', 'array', 'max:' . (int) config('customerupload.max_files', 10)],
            'files.*' => [new CustomerUploadFile],
            'note' => ['nullable', 'string', 'max:' . (int) config('customerupload.note_max', 5000)],
        ];
    }

    public function messages(): array
    {
        return [
            'files.max' => 'U kunt maximaal ' . (int) config('customerupload.max_files', 10)
                . ' bestanden tegelijk versturen.',
            'note.max' => 'Uw toelichting is te lang.',
        ];
    }

    /**
     * Een lege inzending is geen inzending. De melding hangt aan de bestanden,
     * want dat is waar de pagina om vraagt.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->hasFile('files') || filled($this->input('note'))) {
                    return;
                }

                $validator->errors()->add(
                    'files',
                    'Voeg een bestand toe of schrijf een toelichting voordat u verstuurt.',
                );
            },
        ];
    }
}

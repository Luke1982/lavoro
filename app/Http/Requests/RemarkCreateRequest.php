<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\Remark;
use App\Models\ServiceCheckInstance;
use App\Models\Ticket;
use App\Rules\AttachableType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Class RemarkCreateRequest
 *
 * @method object has() Has.
 * @method \App\Models\User user(string $guard = null)
 *
 * @property string $content The content of the remark.
 * @property string $remarkable_type The type of the remarkable entity.
 * @property int $remarkable_id The ID of the remarkable entity.
 */
class RemarkCreateRequest extends FormRequest
{
    /** The record class the request names, if remarks can be placed on it. */
    public function remarkableClass(): ?string
    {
        return Remark::attachableType($this->input('remarkable_type'));
    }

    /**
     * Whoever may see a record may remark on it. A type that is not on the list,
     * or a record that does not exist, is let through to rules(), which refuses
     * it with a message.
     */
    public function authorize(): bool
    {
        $record_class = $this->remarkableClass();
        $record = $record_class === null ? null : $record_class::find($this->integer('remarkable_id'));

        return $record === null || $this->maySee($record);
    }

    /**
     * Each record type is seen the way its own screen decides: an appointment by
     * whoever may give feedback on it, a storing and a werkbon by whoever has it
     * in sight (a technician through the werkbon they carry out), a keurpunt by
     * whoever may read keuringen and may open the werkbon its keuring is for.
     */
    private function maySee(Model $record): bool
    {
        $user = $this->user();

        return match (true) {
            $record instanceof Event => $user->can('provideFeedback', $record),
            $record instanceof Ticket => Ticket::visibleTo($user)->whereKey($record->id)->exists(),
            $record instanceof ServiceCheckInstance => $this->maySeeCheckpoint($record),
            default => $user->can('view', $record),
        };
    }

    private function maySeeCheckpoint(ServiceCheckInstance $checkpoint): bool
    {
        $service_order = $checkpoint->serviceJob?->serviceOrder;

        return $this->user()->hasPermission('servicejob.read')
            && $service_order !== null
            && $this->user()->can('view', $service_order);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => 'required|string|max:2000',
            'remarkable_type' => ['required', 'string', new AttachableType(Remark::ATTACHABLE_TO, 'opmerking')],
            'remarkable_id' => ['required', 'integer', function ($attribute, $value, $fail) {
                $record_class = $this->remarkableClass();

                if ($record_class !== null && !$record_class::whereKey($value)->exists()) {
                    $fail('Dit record bestaat niet (meer).');
                }
            }],
            'user_id' => 'required|integer|exists:users,id',
            'internal' => 'nullable|boolean',
        ];
    }

    /**
     * Get the custom messages for the validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => 'De opmerking is verplicht.',
            'remarkable_type.required' => 'Het type van de opmerking is verplicht.',
            'remarkable_id.required' => 'De ID van de opmerking is verplicht.',
            'user_id.required' => 'De gebruiker is verplicht.',
            'user_id.exists' => 'De opgegeven gebruiker bestaat niet.',
        ];
    }
}

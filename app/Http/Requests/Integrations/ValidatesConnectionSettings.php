<?php

namespace App\Http\Requests\Integrations;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Capability;
use App\Domain\Integrations\Connector;
use App\Domain\Integrations\DocumentForm;
use App\Domain\Integrations\Leading;
use App\Models\IntegrationConnection;
use Illuminate\Validation\Rule;

/**
 * The rules for a connection's credentials, options and per-area switches,
 * read off the connector: what it asks for is what is checked, and a switch
 * for something it cannot do must stay off rather than be stored.
 */
trait ValidatesConnectionSettings
{
    /**
     * A secret that is already stored is not sent back to the screen, so an
     * empty field there means "leave it" and is only required when nothing is
     * stored yet. An option with a default is never required: empty means the
     * default.
     *
     * @param  class-string<Connector>  $connector
     * @return array<string, mixed>
     */
    protected function settingRules(string $connector, ?IntegrationConnection $connection = null): array
    {
        $rules = ['credentials' => ['array'], 'options' => ['array'], 'areas' => ['array']];

        foreach ($connector::credentialFields() as $field) {
            $required = $field->required && !$connection?->hasCredential($field->key);
            $rules['credentials.' . $field->key] = [$required ? 'required' : 'nullable', 'string', 'max:512', ...$field->rules];
        }

        foreach ($connector::optionFields() as $field) {
            $required = $field->required && $field->default === null && !filled($connection?->option($field->key));
            $rules['options.' . $field->key] = [$required ? 'required' : 'nullable', 'string', 'max:255', ...$field->rules];
        }

        foreach (Area::synced() as $area) {
            $rules['areas.' . $area->value . '.read'] = $this->switchRule($connector, Capability::read($area));
            $rules['areas.' . $area->value . '.write'] = $this->switchRule($connector, Capability::write($area));
            $rules['areas.' . $area->value . '.leading'] = ['nullable', Rule::enum(Leading::class)];
        }

        $rules['areas.service_orders.form'] = [
            'nullable',
            Rule::in(array_map(fn (DocumentForm $form) => $form->value, $connector::documentFormsOffered())),
        ];

        return $rules;
    }

    /**
     * @param  class-string<Connector>  $connector
     * @return array<string, string>
     */
    protected function settingAttributes(string $connector): array
    {
        $attributes = ['areas.service_orders.form' => 'vorm van de werkbon'];

        foreach ($connector::credentialFields() as $field) {
            $attributes['credentials.' . $field->key] = mb_strtolower($field->label);
        }

        foreach ($connector::optionFields() as $field) {
            $attributes['options.' . $field->key] = mb_strtolower($field->label);
        }

        return $attributes;
    }

    /**
     * @param  class-string<Connector>  $connector
     * @return array<string, string>
     */
    protected function settingMessages(string $connector): array
    {
        return ['areas.*.*.declined' => $connector::label() . ' kan dit niet.'];
    }

    /**
     * A switch for something the connector cannot do must stay off. Only when
     * sent: declined is one of the rules Laravel also runs on a missing field.
     *
     * @param  class-string<Connector>  $connector
     */
    private function switchRule(string $connector, ?Capability $capability): array
    {
        return $capability && $connector::can($capability) ? ['boolean'] : ['sometimes', 'boolean', 'declined'];
    }
}

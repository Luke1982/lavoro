<?php

namespace App\Http\Requests;

use App\Models\GeneralSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateIntegrationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'mail_transport' => ['required', 'in:graph,smtp'],
            /**
             * Verplicht, en niet omdat het veld niet leeg mag: zonder eigen
             * afzender valt de mail terug op het adres uit de .env, en dat is
             * het adres van wie er toevallig als eerste is opgeleverd.
             */
            'mail_from_address' => ['required', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],

            'graph_azure_tenant_id' => ['nullable', 'string', 'max:255'],
            'graph_client_id' => ['nullable', 'string', 'max:255'],
            'graph_client_secret' => ['nullable', 'string', 'max:512'],
            'graph_user_id' => ['nullable', 'string', 'max:255'],

            'mail_smtp_host' => ['nullable', 'string', 'max:255'],
            'mail_smtp_port' => ['nullable', 'integer', 'between:1,65535'],
            'mail_smtp_scheme' => ['nullable', 'in:smtp,smtps'],
            'mail_smtp_username' => ['nullable', 'string', 'max:255'],
            'mail_smtp_password' => ['nullable', 'string', 'max:512'],

            'snelstart_client_key' => ['nullable', 'string', 'max:512'],
            'snelstart_subscription_key' => ['nullable', 'string', 'max:512'],
        ];
    }

    /**
     * Half ingevuld is erger dan leeg: dan lijkt de koppeling te staan en
     * mislukt elke verzending pas op het moment dat er echt post uit moet.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('mail_transport') === 'graph') {
                foreach (['graph_azure_tenant_id', 'graph_client_id', 'graph_user_id'] as $field) {
                    if (!filled($this->input($field))) {
                        $validator->errors()->add($field, 'Nodig zolang er via Microsoft 365 verstuurd wordt.');
                    }
                }

                if (!filled($this->input('graph_client_secret')) && !$this->secret_is_stored('graph_client_secret')) {
                    $validator->errors()->add('graph_client_secret', 'Nodig zolang er via Microsoft 365 verstuurd wordt.');
                }
            }

            if ($this->input('mail_transport') === 'smtp') {
                foreach (['mail_smtp_host', 'mail_smtp_username'] as $field) {
                    if (!filled($this->input($field))) {
                        $validator->errors()->add($field, 'Nodig zolang er via een eigen mailserver verstuurd wordt.');
                    }
                }

                if (!filled($this->input('mail_smtp_password')) && !$this->secret_is_stored('mail_smtp_password')) {
                    $validator->errors()->add('mail_smtp_password', 'Nodig zolang er via een eigen mailserver verstuurd wordt.');
                }
            }

            $snelstart = ['snelstart_client_key', 'snelstart_subscription_key'];
            $filled = array_filter($snelstart, fn ($field) => filled($this->input($field))
                || $this->secret_is_stored($field));

            if (count($filled) === 1) {
                foreach (array_diff($snelstart, $filled) as $missing) {
                    $validator->errors()->add($missing, 'SnelStart heeft beide sleutels nodig.');
                }
            }
        });
    }

    /**
     * Een geheim dat al is opgeslagen wordt niet teruggestuurd naar het scherm,
     * dus een leeg veld betekent "ongewijzigd" en niet "leeg".
     */
    private function secret_is_stored(string $key): bool
    {
        return filled(GeneralSetting::get($key));
    }
}

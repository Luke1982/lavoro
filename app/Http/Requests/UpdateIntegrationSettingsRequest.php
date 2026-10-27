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
             * Required, and not because the field may not be empty: without a
             * sender of their own the mail falls back on the address from .env,
             * and that is the address of whoever happened to be delivered
             * first.
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
     * Half filled in is worse than empty: then the integration looks like it is
     * there and every send fails only at the moment post really has to go out.
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
     * A secret that is already stored is not sent back to the screen, so an
     * empty field means "unchanged" and not "empty".
     */
    private function secret_is_stored(string $key): bool
    {
        return filled(GeneralSetting::get($key));
    }
}

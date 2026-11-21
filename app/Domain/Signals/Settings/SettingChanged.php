<?php

namespace App\Domain\Signals\Settings;

use App\Domain\Quotes\QuoteLayout;
use App\Domain\Signals\BaseSignal;
use App\Models\GeneralSetting;
use App\Support\RichText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A company-wide setting got another value. Raised by GeneralSetting::set(),
 * the one place every setting is written, and only when the value really
 * changed.
 *
 * A secret -- a mail password, an API key -- is reported as changed or cleared
 * and nothing more: not the value, not the encrypted value, not its length. The
 * same goes for the technical mail settings: only MajorLabel's own screen shows
 * them, and the trail must not show them to a customer's administrator either.
 * For those the previous and new value the signal reports are null.
 *
 * The setting's row is carried only as the record the entry hangs on. Its value
 * column still holds what was stored -- encrypted for a secret, plain for a
 * technical setting -- so nothing that reacts to this signal reads the value
 * from the row: what may be told is in $previous_value and $new_value.
 */
class SettingChanged extends BaseSignal
{
    /**
     * Read by nobody with a customer role, so only administrators: none of the
     * customer's roles can be given a permission that does not exist.
     */
    public const ADMINISTRATORS_ONLY = 'settings.manage';

    /** What a person calls each setting, as on the screen where it is set. */
    public const LABELS = [
        'defaultplannerminutes' => 'Standaardduur van een afspraak (minuten)',
        'planner_leading_color' => 'Kleur in de planner volgt',
        'planner_day_start_hour' => 'Begin van de dag in de planner',
        'planner_day_end_hour' => 'Einde van de dag in de planner',
        'location_tracking_start' => 'Locatie volgen vanaf',
        'location_tracking_end' => 'Locatie volgen tot',
        'location_tracking_days' => 'Locatie volgen op',
        'serviceorder_closing_text' => 'Afsluittekst van de werkbon',
        'serviceorder_min_images' => "Minimaal aantal foto's op een werkbon",
        'allow_override_unavailability' => 'Inplannen bij afwezigheid toestaan',
        'appointment_reminder_minutes' => 'Herinnering voor een afspraak (minuten vooraf)',
        'map_due_colors' => 'Kleuren op de kaart',
        'upcoming_hide_contract_assets' => 'Contractmachines verbergen bij aankomende activiteiten',
        'quote_layout' => 'Opmaak van de offerte',
        'quote_payment_terms' => 'Betalingsvoorwaarden op de offerte',
        'quote_max_upfront_percent' => 'Hoogste aanbetaling op een offerte (%)',
        'quote_hourly_rate' => 'Uurtarief op de offerte',
        'quote_reminder_days' => 'Herinnering voor een offerte na (dagen)',
        'quote_expiry_notice_days' => 'Melding dat een offerte verloopt (dagen vooraf)',
        'quote_terms_kind' => 'Algemene voorwaarden als',
        'quote_terms_path' => 'Algemene voorwaarden (PDF)',
        'quote_terms_name' => 'Bestandsnaam van de algemene voorwaarden',
        'quote_terms_text' => 'Tekst van de algemene voorwaarden',
        'quote_terms_url' => 'Link naar de algemene voorwaarden',
        'mail_transport' => 'Mail versturen via',
        'mail_from_address' => 'Afzenderadres',
        'mail_from_name' => 'Afzendernaam',
        'graph_azure_tenant_id' => 'Microsoft 365 tenant-id',
        'graph_client_id' => 'Microsoft 365 client-id',
        'graph_client_secret' => 'Microsoft 365 clientgeheim',
        'graph_user_id' => 'Microsoft 365 afzender',
        'mail_smtp_host' => 'SMTP-server',
        'mail_smtp_port' => 'SMTP-poort',
        'mail_smtp_scheme' => 'SMTP-beveiliging',
        'mail_smtp_username' => 'SMTP-gebruikersnaam',
        'mail_smtp_password' => 'SMTP-wachtwoord',
        'mail_imap_host' => 'IMAP-server',
        'mail_imap_port' => 'IMAP-poort',
        'mail_imap_username' => 'IMAP-gebruikersnaam',
        'mail_imap_password' => 'IMAP-wachtwoord',
        'mail_imap_sent_folder' => 'Map voor verzonden mail',
        'mail_imap_copy' => 'Kopie in verzonden mail bewaren',
    ];

    /** Who sets these may read their history; every other setting is for administrators. */
    private const PERMISSIONS = [
        'defaultplannerminutes' => 'planner.manage_settings',
        'planner_leading_color' => 'planner.manage_settings',
    ];

    private const YES_NO = ['allow_override_unavailability', 'upcoming_hide_contract_assets', 'mail_imap_copy'];

    private const RICH_TEXT = ['serviceorder_closing_text', 'quote_terms_text'];

    private const WEEKDAYS = [1 => 'ma', 2 => 'di', 3 => 'wo', 4 => 'do', 5 => 'vr', 6 => 'za', 7 => 'zo'];

    /** The settings of the technical management screen, which only MajorLabel opens. */
    private const TECHNICAL_PREFIXES = ['mail_', 'graph_'];

    /**
     * @param  ?string  $previous_value  null for a setting whose value is withheld
     * @param  ?string  $new_value  null for a setting whose value is withheld
     * @param  bool  $cleared  whether a withheld setting was emptied rather than changed
     */
    public function __construct(
        public GeneralSetting $setting,
        public ?string $previous_value,
        public ?string $new_value,
        public bool $cleared = false,
    ) {
        parent::__construct();
    }

    /** Whether the trail may say no more than that this setting changed. */
    public static function withholdsValueOf(string $key): bool
    {
        return in_array($key, GeneralSetting::SECRET_KEYS, true)
            || Str::startsWith($key, self::TECHNICAL_PREFIXES);
    }

    public static function key(): string
    {
        return 'setting.changed';
    }

    public static function label(): string
    {
        return 'Instelling gewijzigd';
    }

    public function subject(): Model
    {
        return $this->setting;
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function activityDescription(): ?string
    {
        $what = self::withholdsValueOf((string) $this->setting->key) && $this->cleared ? ' gewist' : ' gewijzigd';

        return 'Instelling "' . $this->name() . '"' . $what;
    }

    public function changes(): array
    {
        if (self::withholdsValueOf((string) $this->setting->key)) {
            return [[
                'field' => $this->setting->key,
                'label' => $this->name(),
                'old_value' => null,
                'new_value' => null,
                'old_label' => null,
                'new_label' => $this->cleared ? 'gewist' : 'gewijzigd',
            ]];
        }

        return [[
            'field' => $this->setting->key,
            'label' => $this->name(),
            'old_value' => $this->previous_value,
            'new_value' => $this->new_value,
            'old_label' => $this->readable($this->previous_value, previous: true),
            'new_label' => $this->readable($this->new_value),
        ]];
    }

    public function requiredPermission(): ?string
    {
        return match (true) {
            isset(self::PERMISSIONS[$this->setting->key]) => self::PERMISSIONS[$this->setting->key],
            str_starts_with((string) $this->setting->key, 'quote_') => 'quote.manage_settings',
            default => self::ADMINISTRATORS_ONLY,
        };
    }

    private function name(): string
    {
        return self::LABELS[$this->setting->key] ?? (string) $this->setting->key;
    }

    private function readable(?string $value, bool $previous = false): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $key = $this->setting->key;

        return match (true) {
            in_array($key, self::YES_NO, true) => $value === '1' ? 'Ja' : 'Nee',
            in_array($key, self::RICH_TEXT, true) => RichText::plain($value),
            $key === 'planner_leading_color' => $value === 'role' ? 'planrol' : 'afspraaktype',
            $key === 'location_tracking_days' => implode(', ', array_map(
                fn (string $day) => self::WEEKDAYS[(int) $day] ?? $day,
                explode(',', $value),
            )),
            $key === 'quote_layout' => QuoteLayout::describe(json_decode($value, true) ?: []),
            $key === 'quote_terms_path' => $previous ? 'vorige PDF' : 'nieuwe PDF',
            default => $value,
        };
    }
}

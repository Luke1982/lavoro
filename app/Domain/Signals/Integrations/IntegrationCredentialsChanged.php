<?php

namespace App\Domain\Signals\Integrations;

use App\Domain\Signals\BaseSignal;
use App\Models\IntegrationConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone filled in or wiped a key, password or code of a connection on the
 * settings screen. When a connection stops working, "who changed the key, and
 * when" is the first thing asked.
 *
 * Only which fields, by the name the screen gives them: never a value, not even
 * of a field that is not secret. The signal does not carry one. A token renewed
 * by the connection itself is no news and raises nothing.
 */
class IntegrationCredentialsChanged extends BaseSignal
{
    /**
     * @param  array<string, string>  $filled_in  key => label of the fields given a new value
     * @param  array<string, string>  $cleared  key => label of the fields wiped
     */
    public function __construct(
        public IntegrationConnection $connection,
        public array $filled_in,
        public array $cleared,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'integration.credentials_changed';
    }

    public static function label(): string
    {
        return 'Inloggegevens gewijzigd';
    }

    public static function coveredFields(): array
    {
        return ['credentials'];
    }

    public function subject(): Model
    {
        return $this->connection;
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function activityDescription(): ?string
    {
        $parts = array_filter([
            $this->filled_in === [] ? null : 'gewijzigd: ' . implode(', ', $this->filled_in),
            $this->cleared === [] ? null : 'gewist: ' . implode(', ', $this->cleared),
        ]);

        return $parts === [] ? null : 'Inloggegevens ' . implode('; ', $parts);
    }

    public function activityMetadata(): ?array
    {
        return ['connector' => $this->connection->connector];
    }

    public function changes(): array
    {
        $rows = [];

        foreach ([[$this->filled_in, 'gewijzigd'], [$this->cleared, 'gewist']] as [$fields, $what]) {
            foreach ($fields as $key => $label) {
                $rows[] = [
                    'field' => 'credentials.' . $key,
                    'label' => $label,
                    'old_value' => null,
                    'new_value' => null,
                    'old_label' => null,
                    'new_label' => $what,
                ];
            }
        }

        return $rows;
    }

    /** As the settings screen it was changed on, which only integration.manage opens. */
    public function requiredPermission(): ?string
    {
        return 'integration.manage';
    }
}

<?php

namespace App\Domain\Signals\Settings;

use App\Domain\Signals\BaseSignal;
use App\Domain\Signals\Users\NameListChange;
use App\Models\StandardEmail;
use Illuminate\Database\Eloquent\Model;

/**
 * The moments a standard e-mail goes out on changed: from now on it is sent,
 * or offered, when an appointment is made, changed or removed -- or no longer.
 * This is what answers "why did the customer get this mail".
 */
class StandardEmailTriggersChanged extends BaseSignal
{
    public function __construct(public StandardEmail $standard_email, public NameListChange $triggers)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'standard_email.triggers_changed';
    }

    public static function label(): string
    {
        return 'Momenten van standaard e-mail gewijzigd';
    }

    public function subject(): Model
    {
        return $this->standard_email;
    }

    public function activityCategory(): string
    {
        return 'email';
    }

    public function activityDescription(): ?string
    {
        return "Momenten van standaard e-mail '" . $this->standard_email->name . "': "
            . $this->triggers->sentence();
    }

    public function changes(): array
    {
        return [$this->triggers->change('triggers', 'Versturen bij')];
    }
}

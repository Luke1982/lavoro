<?php

namespace App\Domain\Signals\Settings;

use App\Domain\Signals\BaseSignal;
use App\Domain\Signals\Users\NameListChange;
use App\Models\StandardEmail;
use Illuminate\Database\Eloquent\Model;

/** Standard attachments were added to a standard e-mail or taken off it. */
class StandardEmailAttachmentsChanged extends BaseSignal
{
    public function __construct(public StandardEmail $standard_email, public NameListChange $attachments)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'standard_email.attachments_changed';
    }

    public static function label(): string
    {
        return 'Bijlagen van standaard e-mail gewijzigd';
    }

    public function subject(): Model
    {
        return $this->standard_email;
    }

    public function activityCategory(): string
    {
        return 'document';
    }

    public function activityDescription(): ?string
    {
        return "Bijlagen van standaard e-mail '" . $this->standard_email->name . "': "
            . $this->attachments->sentence();
    }

    public function changes(): array
    {
        return [$this->attachments->change('standard_attachments', 'Bijlagen')];
    }
}

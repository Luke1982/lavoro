<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;

enum ProjectStatuses: string
{
    use EnumComboBoxArrayTrait;

    case niet_gestart = 'Niet gestart';
    case gestart = 'Gestart';
    case afgerond = 'Afgerond';
    case geannuleerd = 'Geannuleerd';

    /**
     * A project that is done or called off takes no new work and has no bar in the
     * planner. Reopening is setting it back to one of the other two.
     */
    public function isClosed(): bool
    {
        return $this === self::afgerond || $this === self::geannuleerd;
    }

    /** @return array<int, string> */
    public static function closedValues(): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->isClosed())
        ));
    }
}

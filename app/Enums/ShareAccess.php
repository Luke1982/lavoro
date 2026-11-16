<?php

namespace App\Enums;

/**
 * What the receiving side of a share may do with what was shared, as levels:
 * each one includes the one below it. Viewing; changing it (annotating or
 * renaming a photo); and managing it, which adds removing it.
 */
enum ShareAccess: string
{
    case View = 'view';
    case Edit = 'edit';
    case Manage = 'manage';

    public function allows(ShareAccess $needed): bool
    {
        return $this->rank() >= $needed->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::View => 1,
            self::Edit => 2,
            self::Manage => 3,
        };
    }
}

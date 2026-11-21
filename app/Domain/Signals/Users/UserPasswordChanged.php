<?php

namespace App\Domain\Signals\Users;

use App\Models\User;

/**
 * Someone's password was changed. Never carries the password, not even hashed:
 * the fact and who did it are what an inquiry needs, the value is nobody's
 * business.
 */
class UserPasswordChanged extends UserSignal
{
    public const BY_THEMSELVES = 'self';

    public const BY_SOMEONE_ELSE = 'other';

    public const THROUGH_RESET_LINK = 'reset';

    public function __construct(User $user, public string $way)
    {
        parent::__construct($user);
    }

    public static function key(): string
    {
        return 'user.password_changed';
    }

    public static function label(): string
    {
        return 'Wachtwoord gewijzigd';
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    protected function describe(): ?string
    {
        return match ($this->way) {
            self::THROUGH_RESET_LINK => 'Wachtwoord opnieuw ingesteld via de link uit de e-mail',
            self::BY_SOMEONE_ELSE => 'Nieuw wachtwoord ingesteld voor ' . $this->user->name,
            default => 'Eigen wachtwoord gewijzigd',
        };
    }

    /** Whoever may change someone's account may read when its password changed; nobody else needs to. */
    public function requiredPermission(): ?string
    {
        return 'user.update';
    }
}

<?php

namespace App\Domain\Tools;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Binds an approval to the exact thing that was approved.
 *
 * Somebody agreeing to "Tuesday 09:00, Jeremy, three hours" has agreed to that
 * and nothing else. Between showing it and their clicking yes there is a whole
 * round trip, and a token that merely said "this was confirmed" would let the
 * arguments differ on the way back — the model rewording, a stale panel, a
 * retried request — and the appointment that got made would not be the one
 * anyone read.
 *
 * So the arguments travel inside the token, encrypted rather than signed: it is
 * opaque and tamper-proof in one, and the caller has no reason to read it. What
 * comes back out is what gets carried out; whatever the caller sends alongside
 * is ignored.
 *
 * It also names the person, so an approval cannot be replayed by somebody else,
 * and it goes stale, so one left in a tab overnight is not still good tomorrow.
 */
final class ConfirmationToken
{
    private const LIFETIME_MINUTES = 15;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public readonly string $tool,
        public readonly array $arguments,
        public readonly int $user_id,
        public readonly int $expires_at,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function for(string $tool, array $arguments, User $user): self
    {
        return new self(
            tool: $tool,
            arguments: $arguments,
            user_id: $user->id,
            expires_at: now()->addMinutes(self::LIFETIME_MINUTES)->timestamp,
        );
    }

    public function encoded(): string
    {
        return Crypt::encryptString(json_encode([
            'tool' => $this->tool,
            'arguments' => $this->arguments,
            'user_id' => $this->user_id,
            'expires_at' => $this->expires_at,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Anything not exactly right gives nothing back. There is no partial
     * acceptance here: a token that cannot be read, has expired, or belongs to
     * somebody else is the same as no approval at all.
     */
    public static function decode(?string $encoded, User $user): ?self
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($encoded), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (!is_array($payload) || !isset($payload['tool'], $payload['user_id'], $payload['expires_at'])) {
            return null;
        }

        if ((int) $payload['user_id'] !== $user->id) {
            return null;
        }

        if (now()->timestamp > (int) $payload['expires_at']) {
            return null;
        }

        return new self(
            tool: (string) $payload['tool'],
            arguments: is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [],
            user_id: (int) $payload['user_id'],
            expires_at: (int) $payload['expires_at'],
        );
    }
}

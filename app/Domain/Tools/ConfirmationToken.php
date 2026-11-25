<?php

namespace App\Domain\Tools;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
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
        public readonly ?string $conversation = null,
        public readonly ?string $preview_hash = null,
    ) {}

    /**
     * The conversation is sealed in with the arguments: what gets carried out
     * on the click is what was proposed in that conversation, and the click
     * cannot point it at the files of another one.
     *
     * So is what the person was shown. The same arguments are not the same
     * action once the records under them have moved: "all running contracts"
     * takes in one created after the button appeared, and four percent is four
     * percent of whatever the price has become. The preview names exactly what
     * would change, so a hash of it says whether the click would still do what
     * the button said. A tool whose preview also carries facts nobody decided
     * on, such as the stock left, hands in only what was agreed instead
     * (SealsWhatWasAgreed).
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function for(
        string $tool,
        array $arguments,
        User $user,
        ?string $conversation = null,
        ?string $seal = null,
    ): self {
        return new self(
            tool: $tool,
            arguments: $arguments,
            user_id: $user->id,
            expires_at: now()->addMinutes(self::LIFETIME_MINUTES)->timestamp,
            conversation: $conversation,
            preview_hash: $seal === null ? null : self::hashOf($seal),
        );
    }

    /**
     * Whether this is what was approved: the preview, or the facts the tool
     * seals instead of it.
     *
     * A token without a hash has nothing to compare and holds. That is one
     * for a tool that shows nothing, and also one issued before approvals were
     * sealed, still good for the rest of its quarter of an hour.
     */
    public function showed(string $seal): bool
    {
        return $this->preview_hash === null || hash_equals($this->preview_hash, self::hashOf($seal));
    }

    private static function hashOf(string $seal): string
    {
        return hash('sha256', $seal);
    }

    /**
     * Claims this approval, once and once only.
     *
     * An approval used to be good for its whole quarter of an hour, so one click
     * that arrived twice — a double tap before the first answer lands, a retried
     * request, a replay — wrote twice. Three attempts made three storingen, which
     * is the same fault that once made two werkbonnen out of one job.
     *
     * Cache::add is what makes it safe rather than nearly safe: it writes only if
     * the key is absent, in one operation, so two requests racing cannot both
     * believe they were first. Never released, not even when the tool fails —
     * asking again is right in that case anyway, because whatever went wrong may
     * have changed the situation.
     */
    public static function claim(string $encoded): bool
    {
        return Cache::add(
            'assistant:confirmation:' . hash('sha256', $encoded),
            true,
            now()->addMinutes(self::LIFETIME_MINUTES + 1),
        );
    }

    public function encoded(): string
    {
        return Crypt::encryptString(json_encode([
            'tenant' => tenancy()->initialized ? (string) tenancy()->tenant->getTenantKey() : null,
            'tool' => $this->tool,
            'arguments' => $this->arguments,
            'user_id' => $this->user_id,
            'expires_at' => $this->expires_at,
            'conversation' => $this->conversation,
            'preview_hash' => $this->preview_hash,
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

        /**
         * APP_KEY is for the whole installation and user ids are per tenant, so
         * without this check a token from one tenant is valid in another.
         * Compared strictly: a token from before this change does not have the
         * key and should be refused.
         */
        $tenant = tenancy()->initialized ? (string) tenancy()->tenant->getTenantKey() : null;

        if (($payload['tenant'] ?? null) !== $tenant) {
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
            conversation: is_string($payload['conversation'] ?? null) ? $payload['conversation'] : null,
            preview_hash: is_string($payload['preview_hash'] ?? null) ? $payload['preview_hash'] : null,
        );
    }
}

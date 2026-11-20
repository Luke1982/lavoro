<?php

namespace App\Domain\Integrations\OAuth;

use App\Domain\Integrations\IntegrationUnavailable;
use App\Exceptions\Refusal;
use App\Models\IntegrationConnection;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * The tokens of one connection: kept encrypted with its credentials, and
 * renewed under a lock.
 *
 * The lock matters. Exact hands out a new refresh token with every renewal and
 * invalidates the old one at once, so two jobs renewing side by side leave one
 * of them with a dead token, and the customer has to connect again. Whoever
 * gets the lock reads the tokens fresh from the database first: the job that
 * waited usually finds them renewed already. A renewal is saved at once and
 * must never run inside a transaction (see Synchronizer): a rollback would
 * throw away the only refresh token that still works.
 */
class OAuthTokens
{
    /** How long one request to a token address may take. */
    public const REQUEST_SECONDS = 20;

    /** Held longer than a renewal can take, so a second job never gets in with the same refresh token. */
    private const LOCK_SECONDS = 60;

    /** How long a job waits for another one's renewal. */
    private const WAIT_SECONDS = 30;

    /** Renewed when it runs out within this many seconds. */
    private const MARGIN_SECONDS = 30;

    public function __construct(private IntegrationConnection $connection) {}

    /**
     * Puts tokens on the connection without saving it: the callback of a new
     * connection saves once, when everything about it is known.
     *
     * @param  array{access_token: string, refresh_token?: ?string, expires_in?: ?int}  $tokens
     */
    public function fill(array $tokens): void
    {
        $this->connection->storeCredentials([
            ...$this->connection->credentials(),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $this->connection->credentials()['refresh_token'] ?? null,
            'expires_at' => isset($tokens['expires_in'])
                ? (string) CarbonImmutable::now()->addSeconds((int) $tokens['expires_in'])->getTimestamp()
                : null,
        ]);
    }

    /**
     * A valid access token, renewed through $renew when it is about to run
     * out. $renew gets the refresh token and returns the new tokens.
     *
     * @param  Closure(string): array{access_token: string, refresh_token?: ?string, expires_in?: ?int}  $renew
     */
    public function accessToken(Closure $renew): string
    {
        if (!$this->expiring($this->connection->credentials())) {
            return $this->connection->credentials()['access_token'];
        }

        try {
            return Cache::lock('integration-oauth:' . $this->connection->id, self::LOCK_SECONDS)->block(self::WAIT_SECONDS, function () use ($renew) {
                $credentials = $this->connection->refresh()->credentials();

                if (!$this->expiring($credentials)) {
                    return $credentials['access_token'];
                }

                $refresh_token = $credentials['refresh_token']
                    ?? throw new Refusal('De koppeling met ' . $this->connection->label() . ' is verlopen. Koppel opnieuw onder Instellingen → Koppelingen.');

                $this->fill($renew($refresh_token));
                $this->connection->saveQuietly();

                return $this->connection->credentials()['access_token'];
            });
        } catch (LockTimeoutException) {
            throw new IntegrationUnavailable('De aanmelding bij ' . $this->connection->label() . ' wordt al vernieuwd. Probeer het zo opnieuw.');
        }
    }

    /** @param  array<string, string>  $credentials */
    private function expiring(array $credentials): bool
    {
        if (!filled($credentials['access_token'] ?? null)) {
            throw new Refusal($this->connection->label() . ' is nog niet gekoppeld. Klik op Koppelen onder Instellingen → Koppelingen.');
        }

        $expires_at = $credentials['expires_at'] ?? null;

        return filled($expires_at) && (int) $expires_at <= CarbonImmutable::now()->addSeconds(self::MARGIN_SECONDS)->getTimestamp();
    }
}

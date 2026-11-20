<?php

namespace App\Domain\Integrations\OAuth;

use App\Domain\Integrations\IntegrationUnavailable;
use App\Exceptions\Refusal;
use App\Models\IntegrationConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * MajorLabel's app at one system that is connected through a login (Exact
 * Online, Moneybird): where to send the person, how to turn a code or a refresh
 * token into tokens, and a valid access token for a connection.
 */
class OAuthClient
{
    public function __construct(
        private string $label,
        private string $authorize_url,
        private string $token_url,
        private ?string $client_id,
        private ?string $client_secret,
        private array $authorize_parameters = [],
    ) {}

    public function ready(): bool
    {
        return filled($this->client_id) && filled($this->client_secret);
    }

    public function authorizationUrl(string $redirect_uri, string $state): string
    {
        return $this->authorize_url . '?' . http_build_query([
            'client_id' => $this->client_id,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'state' => $state,
            ...$this->authorize_parameters,
        ]);
    }

    /** @return array{access_token: string, refresh_token: ?string, expires_in: ?int} */
    public function exchangeCode(string $code, string $redirect_uri): array
    {
        return $this->exchange(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect_uri]);
    }

    /** A valid access token for this connection, renewed when it is about to run out. */
    public function accessToken(IntegrationConnection $connection): string
    {
        return (new OAuthTokens($connection))->accessToken(
            fn (string $refresh_token) => $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $refresh_token]),
        );
    }

    /**
     * A refusal from the token address means the login is no good any more
     * and has to be done again; a busy or broken one is worth another try.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int}
     */
    private function exchange(array $grant): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(OAuthTokens::REQUEST_SECONDS)->post($this->token_url, [
                ...$grant,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
            ]);
        } catch (ConnectionException $e) {
            throw new IntegrationUnavailable($this->label . ' is niet bereikbaar: ' . $e->getMessage());
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new IntegrationUnavailable($this->label . ' is even niet beschikbaar (HTTP ' . $response->status() . ').');
        }

        if ($response->failed() || !filled($response->json('access_token'))) {
            throw new Refusal($this->label . ' weigert de aanmelding: '
                . ($response->json('error_description') ?: $response->json('error') ?: 'HTTP ' . $response->status())
                . '. Koppel opnieuw onder Instellingen → Koppelingen.');
        }

        return [
            'access_token' => (string) $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_in' => $response->json('expires_in') !== null ? (int) $response->json('expires_in') : null,
        ];
    }
}

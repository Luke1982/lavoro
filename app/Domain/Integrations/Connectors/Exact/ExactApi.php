<?php

namespace App\Domain\Integrations\Connectors\Exact;

use App\Domain\Integrations\IntegrationUnavailable;
use App\Domain\Integrations\OAuth\OAuthClient;
use App\Domain\Integrations\RecordNotFound;
use App\Exceptions\Refusal;
use App\Models\IntegrationConnection;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The REST API of Exact Online (NL), as one customer's connection, logged in
 * through MajorLabel's app with that customer's own tokens.
 *
 * Exact answers in OData 2: a list as {"d": {"results": [...], "__next": "..."}},
 * one record as {"d": {...}}. An access token lasts ten minutes and every
 * renewal hands out a new refresh token, which is why renewing goes through
 * OAuthClient and the lock in OAuthTokens.
 */
class ExactApi
{
    public function __construct(private IntegrationConnection $connection) {}

    /** MajorLabel's app at Exact. */
    public static function oauth(): OAuthClient
    {
        return new OAuthClient(
            'Exact Online',
            self::base() . '/api/oauth2/auth',
            self::base() . '/api/oauth2/token',
            config('services.exact.client_id'),
            config('services.exact.client_secret'),
            ['force_login' => 0],
        );
    }

    /** One record or a list's first page, unwrapped from "d". */
    public function get(string $path, array $query = []): array
    {
        return $this->unwrap($this->send('GET', $path, ['query' => $query]));
    }

    /** A record, or null when Exact does not have it (any more). */
    public function find(string $path, array $query = []): ?array
    {
        try {
            $found = $this->get($path, $query);
        } catch (RecordNotFound) {
            return null;
        }

        return array_is_list($found) ? ($found[0] ?? null) : $found;
    }

    public function post(string $path, array $payload): array
    {
        return $this->unwrap($this->send('POST', $path, ['json' => $payload]));
    }

    /** Exact answers a PUT with 204 and no body: read the record again when you need it. */
    public function put(string $path, array $payload): void
    {
        $this->send('PUT', $path, ['json' => $payload]);
    }

    public function delete(string $path): void
    {
        $this->send('DELETE', $path, []);
    }

    /**
     * Every record of a list, following "__next". Only an address on Exact's
     * own API is followed, never one already read.
     */
    public function pages(string $path, array $query = []): Generator
    {
        $response = $this->send('GET', $path, ['query' => $query]);
        $followed = [];

        while (true) {
            yield from $response->json('d.results') ?? [];

            $next = $response->json('d.__next');

            if (!$next) {
                return;
            }

            if (!str_starts_with($next, self::base() . '/api/v1/') || isset($followed[$next])) {
                throw new Refusal('Exact Online verwees voor de volgende pagina naar een adres dat Lavoro niet volgt: ' . $next);
            }

            $followed[$next] = true;
            $response = $this->send('GET', substr($next, strlen(self::base() . '/api/v1')), []);
        }
    }

    private function send(string $method, string $path, array $options): Response
    {
        try {
            $response = Http::withToken($this->token())->acceptJson()->timeout(60)
                ->send($method, self::base() . '/api/v1' . $path, $options);
        } catch (ConnectionException $e) {
            throw new IntegrationUnavailable('Exact Online is niet bereikbaar: ' . $e->getMessage());
        }

        if ($response->failed()) {
            throw $this->refusal($response);
        }

        return $response;
    }

    private function token(): string
    {
        return self::oauth()->accessToken($this->connection);
    }

    private function unwrap(Response $response): array
    {
        $d = $response->json('d') ?? [];

        return $d['results'] ?? $d;
    }

    private function refusal(Response $response): Refusal
    {
        $status = $response->status();
        $said = $response->json('error.message.value') ?? $response->json('error.message');
        $said = is_string($said) && filled($said) ? $said : null;

        return match (true) {
            $status === 404 => new RecordNotFound('Exact Online kent dit record niet.'),
            $status === 401 => new Refusal('Exact Online weigert de aanmelding. Koppel opnieuw onder Instellingen → Koppelingen.'),
            $status === 403 => new Refusal('Exact Online staat dit niet toe' . ($said ? ': ' . $said : '') . '. Heeft de gebruiker die koppelde rechten op deze administratie?'),
            $status === 429 || $status >= 500 => new IntegrationUnavailable('Exact Online is even niet beschikbaar (HTTP ' . $status . ').'),
            default => new Refusal('Exact Online weigerde dit (HTTP ' . $status . ')' . ($said ? ': ' . $said : '.')),
        };
    }

    private static function base(): string
    {
        return rtrim((string) config('services.exact.base_url'), '/');
    }
}

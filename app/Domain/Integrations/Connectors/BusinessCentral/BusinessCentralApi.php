<?php

namespace App\Domain\Integrations\Connectors\BusinessCentral;

use App\Domain\Integrations\IntegrationUnavailable;
use App\Domain\Integrations\RecordNotFound;
use App\Exceptions\Refusal;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The standard API (v2.0) of Business Central online, for one customer's
 * environment, logged in as an app: OAuth client credentials against the
 * customer's own Microsoft Entra tenant, no user involved, so it works from a
 * queue worker at night.
 *
 * Paths are relative to the environment's API root; company() prefixes the
 * company. Every failure comes out as a refusal saying what Business Central
 * said.
 */
class BusinessCentralApi
{
    private const SCOPE = 'https://api.businesscentral.dynamics.com/.default';

    public function __construct(
        private string $tenant_id,
        private string $client_id,
        private string $client_secret,
        private string $environment,
    ) {}

    public static function company(string $company_id, string $path = ''): string
    {
        if (!Str::isUuid($company_id)) {
            throw new Refusal('Het bedrijf voor Business Central is geen geldig ID: ' . $company_id . '.');
        }

        return '/companies(' . $company_id . ')' . $path;
    }

    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, ['query' => $query])->json() ?? [];
    }

    /** A record, or null when Business Central does not have it (any more). */
    public function find(string $path): ?array
    {
        try {
            return $this->get($path);
        } catch (RecordNotFound) {
            return null;
        }
    }

    public function post(string $path, array $payload = []): array
    {
        return $this->send('POST', $path, ['json' => $payload])->json() ?? [];
    }

    /**
     * Business Central refuses a change without an ETag. $etag is the one the
     * record was read with; '*' means "whatever it is now", for records whose
     * clashes the synchronizer already settled.
     */
    public function patch(string $path, array $payload, string $etag = '*'): array
    {
        return $this->send('PATCH', $path, ['json' => $payload], ['If-Match' => $etag])->json() ?? [];
    }

    public function delete(string $path, string $etag = '*'): void
    {
        $this->send('DELETE', $path, [], ['If-Match' => $etag]);
    }

    /**
     * Every record of a list, following @odata.nextLink: Business Central
     * pages its answers and says where the next page is. Only an address in
     * this same environment is followed, or the token would go wherever an
     * answer pointed; and never one already read, or a broken answer would
     * keep a pull going forever.
     */
    public function pages(string $path, array $query = []): Generator
    {
        $page = $this->get($path, $query);
        $followed = [];

        while (true) {
            yield from $page['value'] ?? [];

            $next = $page['@odata.nextLink'] ?? null;

            if (!$next) {
                return;
            }

            if (!str_starts_with($next, $this->root() . '/')) {
                throw new Refusal('Business Central verwees voor de volgende pagina naar een ander adres: ' . $next);
            }

            if (isset($followed[$next])) {
                throw new Refusal('Business Central verwees voor de volgende pagina naar een pagina die al gelezen was.');
            }

            $followed[$next] = true;
            $page = $this->send('GET', substr($next, strlen($this->root())), [])->json() ?? [];
        }
    }

    private function send(string $method, string $path, array $options, array $headers = []): Response
    {
        $url = $this->root() . $path;

        try {
            $response = $this->request()->withHeaders($headers)->send($method, $url, $options);
        } catch (ConnectionException $e) {
            throw new IntegrationUnavailable('Business Central is niet bereikbaar: ' . $e->getMessage());
        }

        if ($response->failed()) {
            throw $this->refusal($response);
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->token())->acceptJson()->timeout(60);
    }

    /**
     * Cached for as long as Microsoft says it lasts, minus a minute, and per
     * set of credentials: new credentials never reuse the token of the old.
     * The cache is per customer already.
     */
    private function token(): string
    {
        $key = 'businesscentral.token.' . sha1($this->tenant_id . '|' . $this->client_id . '|' . $this->client_secret);

        if ($token = Cache::get($key)) {
            return $token;
        }

        try {
            $response = Http::asForm()->timeout(30)->post(
                rtrim(config('services.businesscentral.login_url'), '/') . '/' . rawurlencode($this->tenant_id) . '/oauth2/v2.0/token',
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret,
                    'scope' => self::SCOPE,
                ],
            );
        } catch (ConnectionException $e) {
            throw new IntegrationUnavailable('Microsoft is niet bereikbaar: ' . $e->getMessage());
        }

        if ($response->failed()) {
            throw new Refusal('Microsoft weigert de aanmelding voor Business Central: '
                . ($response->json('error_description') ?: 'HTTP ' . $response->status())
                . ' Controleer de Directory (tenant) ID, de Application (client) ID en het clientgeheim.');
        }

        $token = (string) $response->json('access_token');
        Cache::put($key, $token, max(60, (int) $response->json('expires_in', 3600) - 60));

        return $token;
    }

    private function refusal(Response $response): Refusal
    {
        $status = $response->status();
        $said = $response->json('error.message');

        return match (true) {
            $status === 404 => new RecordNotFound('Business Central kent dit record niet.'),
            $status === 401 => new Refusal('Business Central weigert de aanmelding. Is de app in Business Central '
                . 'toegevoegd onder Microsoft Entra-toepassingen, en staat hij op Ingeschakeld?'),
            $status === 403 => new Refusal('Business Central staat dit de app niet toe'
                . ($said ? ': ' . $said : '') . '. Geef de app de nodige machtigingensets.'),
            $status === 429 || $status >= 500 => new IntegrationUnavailable(
                'Business Central is even niet beschikbaar (HTTP ' . $status . ').'
            ),
            default => new Refusal('Business Central weigerde dit (HTTP ' . $status . ')' . ($said ? ': ' . $said : '.')),
        };
    }

    private function root(): string
    {
        return rtrim(config('services.businesscentral.api_url'), '/')
            . '/' . rawurlencode($this->tenant_id) . '/' . rawurlencode($this->environment) . '/api/v2.0';
    }
}

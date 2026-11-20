<?php

namespace App\Domain\Integrations\Connectors\Moneybird;

use App\Domain\Integrations\IntegrationUnavailable;
use App\Domain\Integrations\OAuth\OAuthClient;
use App\Domain\Integrations\RecordNotFound;
use App\Exceptions\Refusal;
use App\Models\IntegrationConnection;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * The Moneybird API (v2), as one customer's connection, logged in through
 * MajorLabel's app with that customer's own token.
 *
 * Moneybird allows 150 calls per five minutes per address -- Lavoro's server,
 * for every customer together -- so lists go a hundred records a page, the
 * most it hands out.
 */
class MoneybirdApi
{
    public const PAGE_SIZE = 100;

    /** What Lavoro needs: contacts and sales invoices, and products and webhooks (settings). */
    private const SCOPES = 'sales_invoices settings';

    public function __construct(private IntegrationConnection $connection) {}

    /** MajorLabel's app at Moneybird. */
    public static function oauth(): OAuthClient
    {
        $oauth = rtrim((string) config('services.moneybird.oauth_url'), '/');

        return new OAuthClient(
            'Moneybird',
            $oauth . '/authorize',
            $oauth . '/token',
            config('services.moneybird.client_id'),
            config('services.moneybird.client_secret'),
            ['scope' => self::SCOPES],
        );
    }

    /** @return array<int, array{id: string, name: string}> The administrations this login may see. */
    public function administrations(): array
    {
        return array_map(
            fn (array $administration) => ['id' => (string) $administration['id'], 'name' => (string) ($administration['name'] ?? '')],
            $this->send('GET', self::api() . '/administrations.json', [])->json() ?? [],
        );
    }

    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $this->url($path), ['query' => $query])->json() ?? [];
    }

    public function find(string $path): ?array
    {
        try {
            return $this->get($path);
        } catch (RecordNotFound) {
            return null;
        }
    }

    public function post(string $path, array $payload): array
    {
        return $this->send('POST', $this->url($path), ['json' => $payload])->json() ?? [];
    }

    public function patch(string $path, array $payload): array
    {
        return $this->send('PATCH', $this->url($path), ['json' => $payload])->json() ?? [];
    }

    public function delete(string $path): void
    {
        $this->send('DELETE', $this->url($path), []);
    }

    /** Every record of a list, a page at a time, until a page comes back short. */
    public function pages(string $path, array $query = []): Generator
    {
        $page = 1;
        $previous_first = null;

        do {
            $records = $this->get($path, [...$query, 'page' => $page, 'per_page' => self::PAGE_SIZE]);

            if ($records !== [] && ($records[0]['id'] ?? null) === $previous_first) {
                throw new Refusal('Moneybird gaf dezelfde pagina van ' . $path . ' twee keer.');
            }

            $previous_first = $records[0]['id'] ?? null;
            $page++;

            yield from $records;
        } while (count($records) === self::PAGE_SIZE);
    }

    private function send(string $method, string $url, array $options): Response
    {
        try {
            $response = Http::withToken($this->token())->acceptJson()->timeout(60)->send($method, $url, $options);
        } catch (ConnectionException $e) {
            throw new IntegrationUnavailable('Moneybird is niet bereikbaar: ' . $e->getMessage());
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

    private function url(string $path): string
    {
        $administration = (string) $this->connection->option('administration_id');

        if (!ctype_digit($administration)) {
            throw new Refusal('Moneybird is nog niet gekoppeld aan een administratie. Koppel opnieuw onder Instellingen → Koppelingen.');
        }

        return self::api() . '/' . $administration . $path;
    }

    private function refusal(Response $response): Refusal
    {
        $status = $response->status();
        $said = is_string($response->json('error')) ? $response->json('error') : null;
        $details = collect(Arr::dot((array) $response->json('details', [])))
            ->map(fn ($message, $field) => preg_replace('/\.\d+$/', '', (string) $field) . ': ' . $message)
            ->implode(' | ');

        return match (true) {
            $status === 404 => new RecordNotFound('Moneybird kent dit record niet.'),
            $status === 401 => new Refusal('Moneybird weigert de aanmelding. Koppel opnieuw onder Instellingen → Koppelingen.'),
            $status === 403 => new Refusal('Moneybird staat dit niet toe' . ($said ? ': ' . $said : '') . '. Koppel opnieuw en geef Lavoro de gevraagde rechten.'),
            $status === 429 || $status >= 500 => new IntegrationUnavailable('Moneybird is even niet beschikbaar (HTTP ' . $status . ').'),
            default => new Refusal('Moneybird weigerde dit (HTTP ' . $status . ')' . ($said || $details ? ': ' . trim($said . ' ' . $details) : '.')),
        };
    }

    private static function api(): string
    {
        return rtrim((string) config('services.moneybird.api_url'), '/');
    }
}

<?php

namespace App\Domain\Integrations\Connectors\SnelStart;

use App\Domain\Integrations\IntegrationUnavailable;
use App\Domain\Integrations\RecordNotFound;
use App\Exceptions\Refusal;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The SnelStart B2B API, with one customer's keys. Every failure comes out as a
 * refusal that says what SnelStart said, so the button and the run log both
 * read the reason instead of "Er is een serverfout opgetreden".
 *
 * The address of the service is in config, the same for everyone; the keys are
 * the connection's and there is no falling back: a call without keys of its
 * own would put one customer's invoices in another's bookkeeping.
 */
class SnelStartApi
{
    /** The most SnelStart hands out per request. */
    public const PAGE_SIZE = 500;

    public function __construct(
        private string $client_key,
        private string $subscription_key,
    ) {}

    public function get(string $uri, array $query = []): mixed
    {
        return $this->send(fn (PendingRequest $request) => $request->get($this->url($uri), $query));
    }

    public function post(string $uri, array $payload): mixed
    {
        return $this->send(fn (PendingRequest $request) => $request->post($this->url($uri), $payload));
    }

    public function put(string $uri, array $payload): mixed
    {
        return $this->send(fn (PendingRequest $request) => $request->put($this->url($uri), $payload));
    }

    /** A record by id, or null when SnelStart does not have it (any more). */
    public function find(string $uri): ?array
    {
        try {
            return $this->get($uri);
        } catch (RecordNotFound) {
            return null;
        }
    }

    /**
     * Every record, page by page: one request returns at most 500, and
     * everything after that was silently left out before.
     */
    public function pages(string $uri, array $query = []): Generator
    {
        $skip = 0;
        $previous_first = null;

        do {
            $page = $this->get($uri, $query + ['$top' => self::PAGE_SIZE, '$skip' => $skip]) ?? [];

            if ($page === []) {
                return;
            }

            if (($page[0]['id'] ?? null) === $previous_first) {
                throw new Refusal("SnelStart gaf dezelfde pagina van {$uri} twee keer: \$skip werd genegeerd.");
            }

            $previous_first = $page[0]['id'] ?? null;
            $skip += self::PAGE_SIZE;

            yield from $page;
        } while (count($page) === self::PAGE_SIZE);
    }

    public function country(string $id): ?array
    {
        return Cache::remember('snelstart.land.' . $id, now()->addDay(), fn () => $this->find('/landen/' . $id));
    }

    public function countryByIso(string $iso): ?array
    {
        $iso = strtoupper(trim($iso));

        $countries = Cache::remember('snelstart.landen', now()->addDay(), fn () => $this->get('/landen') ?? []);

        foreach ($countries as $country) {
            if (strtoupper($country['landcodeISO'] ?? '') === $iso) {
                return $country;
            }
        }

        return null;
    }

    /**
     * The token is cached per key, so new keys never reuse the token of the
     * old ones. The cache is per customer already.
     */
    private function token(): string
    {
        return Cache::remember('snelstart.token.' . sha1($this->client_key), now()->addSeconds(3500), function () {
            try {
                $response = Http::asForm()->timeout(30)->post(config('services.snelstart.auth_url'), [
                    'grant_type' => 'clientkey',
                    'clientkey' => $this->client_key,
                ]);
            } catch (ConnectionException $e) {
                throw new IntegrationUnavailable('SnelStart is niet bereikbaar: ' . $e->getMessage());
            }

            if ($response->failed()) {
                throw new Refusal('SnelStart weigert de clientsleutel (HTTP ' . $response->status() . '). '
                    . 'Controleer de sleutels onder Instellingen → Koppelingen.');
            }

            return (string) $response->json('access_token');
        });
    }

    private function send(callable $call): mixed
    {
        $request = Http::withToken($this->token())
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $this->subscription_key])
            ->timeout(60);

        try {
            return $call($request)->throw()->json();
        } catch (ConnectionException $e) {
            throw new IntegrationUnavailable('SnelStart is niet bereikbaar: ' . $e->getMessage());
        } catch (RequestException $e) {
            throw $this->refusal($e);
        }
    }

    private function refusal(RequestException $e): Refusal
    {
        $status = $e->response->status();

        if ($status === 404) {
            return new RecordNotFound('SnelStart kent dit record niet.');
        }

        if (in_array($status, [401, 403], true)) {
            return new Refusal('SnelStart weigert de sleutels. Controleer ze onder Instellingen → Koppelingen.');
        }

        if ($status === 429 || $status >= 500) {
            return new IntegrationUnavailable('SnelStart is even niet beschikbaar (HTTP ' . $status . ').');
        }

        $problems = [];

        foreach ((array) ($e->response->json('modelState') ?? []) as $field => $messages) {
            foreach ((array) $messages as $message) {
                $problems[] = $field . ': ' . $message;
            }
        }

        return new Refusal('SnelStart weigerde dit (HTTP ' . $status . ')'
            . ($problems ? ': ' . implode(' | ', $problems) : '.'));
    }

    private function url(string $uri): string
    {
        return rtrim(config('services.snelstart.api_base'), '/') . $uri;
    }
}

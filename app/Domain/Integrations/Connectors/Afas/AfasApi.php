<?php

namespace App\Domain\Integrations\Connectors\Afas;

use App\Domain\Integrations\IntegrationUnavailable;
use App\Domain\Integrations\RecordNotFound;
use App\Exceptions\Refusal;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * AFAS Profit's REST service, for one customer's environment, with the token
 * of a user in that customer's app connector.
 *
 * Reading goes through GetConnectors the customer defines in Profit and
 * activates in the app connector; writing through UpdateConnectors. Profit
 * explains a refusal in a header, X-Profit-Error, base64-encoded.
 */
class AfasApi
{
    /** AFAS documents no maximum; this keeps one answer well under its 15-minute call limit. */
    public const PAGE_SIZE = 500;

    private const HOSTS = ['production' => 'rest', 'test' => 'resttest', 'accept' => 'restaccept'];

    public function __construct(
        private string $environment,
        private string $type,
        private string $token,
    ) {
        /** Profit shows the token wrapped in its XML; pasted whole, it is unwrapped here rather than wrapped twice. */
        if (preg_match('/<data>(.*?)<\/data>/s', $this->token, $inner)) {
            $this->token = trim($inner[1]);
        }

        if (!ctype_digit($this->environment) || !isset(self::HOSTS[$this->type])) {
            throw new Refusal('AFAS is niet goed ingesteld: het omgevingsnummer is alleen cijfers, en de omgeving is productie, test of accept.');
        }
    }

    /** @return array<int, array<string, mixed>> One page of a GetConnector. */
    public function rows(string $connector, array $query = []): array
    {
        return $this->send('GET', '/connectors/' . rawurlencode($connector), ['query' => $query])->json('rows') ?? [];
    }

    /** Every row of a GetConnector, sorted so the pages do not overlap. */
    public function pages(string $connector, string $order_by, array $query = []): Generator
    {
        $skip = 0;

        do {
            $rows = $this->rows($connector, [...$query, 'skip' => $skip, 'take' => self::PAGE_SIZE, 'orderbyfieldids' => $order_by]);
            $skip += self::PAGE_SIZE;

            yield from $rows;
        } while (count($rows) === self::PAGE_SIZE);
    }

    /** @return array<string, mixed> */
    public function metainfo(string $path = ''): array
    {
        return $this->send('GET', '/metainfo' . $path, [])->json() ?? [];
    }

    /** @return array<string, mixed> Whatever the UpdateConnector answers, such as a new number. */
    public function insert(string $connector, array $payload): array
    {
        return $this->send('POST', '/connectors/' . rawurlencode($connector), ['json' => $payload])->json() ?? [];
    }

    private function send(string $method, string $path, array $options): Response
    {
        try {
            $response = Http::withHeaders(['Authorization' => 'AfasToken ' . base64_encode('<token><version>1</version><data>' . $this->token . '</data></token>')])
                ->acceptJson()
                ->timeout(120)
                ->send($method, $this->root() . $path, $options);
        } catch (ConnectionException $e) {
            throw new IntegrationUnavailable('AFAS is niet bereikbaar: ' . $e->getMessage());
        }

        if ($response->failed()) {
            throw $this->refusal($response);
        }

        return $response;
    }

    private function refusal(Response $response): Refusal
    {
        $status = $response->status();
        $said = base64_decode((string) $response->header('X-Profit-Error'), true) ?: $response->json('externalMessage');
        $said = is_string($said) && filled($said) ? trim($said) : null;

        return match (true) {
            $status === 404 => new RecordNotFound('AFAS kent dit niet' . ($said ? ': ' . $said : '.')),
            in_array($status, [400, 401], true) && !$said => new Refusal('AFAS weigert de token of de app-connector. '
                . 'Controleer het omgevingsnummer, de token en of de connectors in de app-connector staan.'),
            $status === 429 || $status >= 502 => new IntegrationUnavailable('AFAS is even niet beschikbaar (HTTP ' . $status . ').'),
            default => new Refusal('AFAS weigerde dit (HTTP ' . $status . ')' . ($said ? ': ' . $said : '.')),
        };
    }

    private function root(): string
    {
        return 'https://' . $this->environment . '.' . self::HOSTS[$this->type] . '.afas.online/ProfitRestServices';
    }
}

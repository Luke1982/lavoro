<?php

namespace App\Services;

use App\Exceptions\SnelStartNotConfigured;
use App\Models\GeneralSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SnelStartClient
{
    protected string $authUrl;

    protected string $apiBase;

    protected string $clientKey;

    protected string $subscriptionKey;

    /**
     * De sleutels komen van de klant en niet uit de .env. Er is hier geen
     * terugval: een SnelStart-aanroep zonder eigen sleutels zou de facturen van
     * de ene klant in de boekhouding van de andere zetten. Het adres van de
     * dienst zelf staat wel gewoon in de config — dat is voor iedereen gelijk.
     */
    public function __construct()
    {
        $cfg = config('services.snelstart');
        $this->authUrl = $cfg['auth_url'];
        $this->apiBase = $cfg['api_base'];
        $this->clientKey = (string) GeneralSetting::get('snelstart_client_key', '');
        $this->subscriptionKey = (string) GeneralSetting::get('snelstart_subscription_key', '');

        if (!filled($this->clientKey) || !filled($this->subscriptionKey)) {
            throw new SnelStartNotConfigured;
        }
    }

    public static function isConfigured(): bool
    {
        return tenancy()->initialized
            && filled(GeneralSetting::get('snelstart_client_key'))
            && filled(GeneralSetting::get('snelstart_subscription_key'));
    }

    protected function getAccessToken(): string
    {
        return Cache::remember('snelstart.token', now()->addSeconds(3500), function () {
            $response = Http::asForm()->post($this->authUrl, [
                'grant_type' => 'clientkey',
                'clientkey' => $this->clientKey,
            ])->throw();

            return $response->json('access_token');
        });
    }

    public function get(string $uri, array $query = [])
    {
        return Http::withToken($this->getAccessToken())
            ->withHeaders([
                'Ocp-Apim-Subscription-Key' => $this->subscriptionKey,
            ])
            ->get($this->apiBase . $uri, $query)
            ->throw()
            ->json();
    }

    public function post(string $uri, array $payload = [])
    {
        return Http::withToken($this->getAccessToken())
            ->withHeaders([
                'Ocp-Apim-Subscription-Key' => $this->subscriptionKey,
            ])
            ->post($this->apiBase . $uri, $payload)
            ->throw()
            ->json();
    }

    public function getCountry(string $uuid): array
    {
        return Cache::remember("snelstart.land.{$uuid}", now()->addDay(), function () use ($uuid) {
            $response = Http::withToken($this->getAccessToken())
                ->withHeader('Ocp-Apim-Subscription-Key', $this->subscriptionKey)
                ->get("{$this->apiBase}/landen/{$uuid}")
                ->throw();

            return $response->json();
        });
    }

    public function getCountryByIso(string $iso): ?array
    {
        $iso = strtoupper(trim($iso));

        return Cache::remember("snelstart.land.iso.{$iso}", now()->addDay(), function () use ($iso) {
            $response = Http::withToken($this->getAccessToken())
                ->withHeaders([
                    'Ocp-Apim-Subscription-Key' => $this->subscriptionKey,
                ])
                ->get($this->apiBase . '/landen')
                ->throw()
                ->json();
            foreach ($response as $item) {
                if (isset($item['landcodeISO']) && strtoupper($item['landcodeISO']) === $iso) {
                    return $item;
                }
            }

            return null;
        });
    }
}

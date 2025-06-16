<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SnelStartClient
{
    protected string $authUrl;
    protected string $apiBase;
    protected string $clientKey;
    protected string $subscriptionKey;

    public function __construct()
    {
        $cfg = config('services.snelstart');
        $this->authUrl         = $cfg['auth_url'];
        $this->apiBase         = $cfg['api_base'];
        $this->clientKey       = $cfg['client_key'];
        $this->subscriptionKey = $cfg['subscription_key'];
    }

    protected function getAccessToken(): string
    {
        return Cache::remember('snelstart.token', now()->addSeconds(3500), function () {
            $response = Http::asForm()->post($this->authUrl, [
                'grant_type' => 'clientkey',
                'clientkey'  => $this->clientKey,
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
}

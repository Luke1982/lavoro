<?php

namespace App\Services\Google;

use App\Models\GoogleCalendarIntegration;
use Google\Client;
use Illuminate\Support\Facades\Log;

class GoogleClientFactory
{
    public function unauthenticatedClient(): Client
    {
        $client = new Client();
        $client->setClientId(config('google.client_id'));
        $client->setClientSecret(config('google.client_secret'));
        $client->setRedirectUri(config('google.oauth_redirect_uri'));
        $client->setScopes(config('google.scopes'));
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);
        return $client;
    }

    public function clientFor(GoogleCalendarIntegration $integration): Client
    {
        $client = $this->unauthenticatedClient();

        $client->setAccessToken([
            'access_token' => $integration->access_token,
            'refresh_token' => $integration->refresh_token,
            'expires_in' => max(0, $integration->expires_at->getTimestamp() - now()->getTimestamp()),
            'created' => 0,
        ]);

        if ($integration->expires_at->lt(now()->addSeconds(60))) {
            $this->refresh($client, $integration);
        }

        return $client;
    }

    private function refresh(Client $client, GoogleCalendarIntegration $integration): void
    {
        try {
            $token_set = $client->fetchAccessTokenWithRefreshToken($integration->refresh_token);
        } catch (\Throwable $e) {
            $this->disable($integration, $e->getMessage());
            throw $e;
        }

        if (isset($token_set['error'])) {
            $this->disable($integration, $token_set['error_description'] ?? $token_set['error']);
            throw new \RuntimeException('Google token refresh failed: ' . ($token_set['error_description'] ?? $token_set['error']));
        }

        $integration->access_token = $token_set['access_token'];
        if (!empty($token_set['refresh_token'])) {
            $integration->refresh_token = $token_set['refresh_token'];
        }
        $integration->expires_at = now()->addSeconds((int) ($token_set['expires_in'] ?? 3600));
        $integration->save();
    }

    private function disable(GoogleCalendarIntegration $integration, string $reason): void
    {
        $integration->disabled_at = now();
        $integration->last_error = 'Token refresh failed: ' . $reason;
        $integration->save();
        Log::warning('Google integration disabled', [
            'integration_id' => $integration->id,
            'reason' => $reason,
        ]);
    }
}

<?php

namespace App\Mail\Transports;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;

class GraphTransport extends AbstractTransport
{
    private string $graphEndpoint;

    public function __construct(
        private string $tenantId,
        private string $clientId,
        private string $clientSecret,
        private string $fromAddress,
        private ?string $userId = null,
        ?string $graphEndpoint = null,
        $dispatcher = null,
        ?LoggerInterface $logger = null
    ) {
        $this->graphEndpoint = $graphEndpoint ?: 'https://graph.microsoft.com/v1.0';
        // Basic GUID format validation to surface configuration issues early
        $guidPattern = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
        if (!preg_match($guidPattern, $this->tenantId)) {
            throw new \InvalidArgumentException(
                'GRAPH_TENANT_ID invalid (expected GUID xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)'
            );
        }
        if (!preg_match($guidPattern, $this->clientId)) {
            throw new \InvalidArgumentException('GRAPH_CLIENT_ID appears invalid (expect GUID).');
        }
        parent::__construct($dispatcher instanceof EventDispatcherInterface ? $dispatcher : null, $logger);
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        if (!$email instanceof Email) {
            return; // unsupported
        }

        $token = $this->getAccessToken();

        $payload = $this->convertEmailToGraphPayload($email);

        $user = $this->userId ?: $this->fromAddress;
        $url = rtrim($this->graphEndpoint, '/') . "/users/{$user}/sendMail";

        $response = $this->http()->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        ])->post($url, $payload);

        if ($response->failed()) {
            $body = $response->json();
            $messageText = $body['error']['message'] ?? $response->body();
            throw new \RuntimeException('Microsoft Graph send failed: ' . $messageText);
        }
    }

    private function convertEmailToGraphPayload(Email $email): array
    {
        $mapAddress = function ($a) {
            return [
                'emailAddress' => [
                    'address' => $a->getAddress(),
                    'name' => $a->getName(),
                ],
            ];
        };

        $to = collect($email->getTo())->map($mapAddress);
        $cc = collect($email->getCc())->map($mapAddress);
        $bcc = collect($email->getBcc())->map($mapAddress);

        $bodyContent = $email->getHtmlBody() ?: $email->getTextBody() ?: '';
        $bodyIsHtml = (bool) $email->getHtmlBody();

        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $attachments[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $attachment
                    ->getPreparedHeaders()
                    ->getHeaderParameter('content-disposition', 'filename')
                    ?? 'attachment',
                'contentBytes' => base64_encode($attachment->getBody()),
                'contentType' => $attachment->getMediaType() . '/' . $attachment->getMediaSubtype(),
            ];
        }

        return [
            'message' => [
                'subject' => $email->getSubject(),
                'body' => [
                    'contentType' => $bodyIsHtml ? 'HTML' : 'Text',
                    'content' => $bodyContent,
                ],
                'toRecipients' => $to->values()->all(),
                'ccRecipients' => $cc->values()->all(),
                'bccRecipients' => $bcc->values()->all(),
                'attachments' => $attachments,
            ],
            'saveToSentItems' => true,
        ];
    }

    private function getAccessToken(): string
    {
        $tenant = $this->tenantId;
        $url = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";

        $response = $this->http()->asForm()->post($url, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);
        if (!$response->ok()) {
            $body = $response->json();
            $err = $body['error'] ?? 'unknown_error';
            $desc = $body['error_description'] ?? $response->body();
            throw new \RuntimeException("Token request failed ({$err}): {$desc}");
        }
        $token = $response->json('access_token');
        if (!$token) {
            throw new \RuntimeException('Token response missing access_token');
        }
        return $token;
    }

    private function http()
    {
        return Http::retry(2, 250); // simple helper
    }

    public function __toString(): string
    {
        return 'graph';
    }
}

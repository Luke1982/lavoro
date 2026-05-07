<?php

namespace App\Services;

use RuntimeException;

class ImapSentCopier
{
    public function isConfigured(): bool
    {
        return !empty(config('imap.host'))
            && !empty(config('imap.username'))
            && !empty(config('imap.password'));
    }

    private function serverString(): string
    {
        $host       = config('imap.host');
        $port       = config('imap.port', 993);
        $encryption = config('imap.encryption', 'ssl');
        $validate   = config('imap.validate_cert', false);

        $flags = '/imap';
        if ($encryption === 'ssl') {
            $flags .= '/ssl';
        } elseif ($encryption === 'tls') {
            $flags .= '/tls';
        }
        if (!$validate) {
            $flags .= '/novalidate-cert';
        }

        return '{' . $host . ':' . $port . $flags . '}';
    }

    /** @return resource */
    private function openConnection(): mixed
    {
        if (!function_exists('imap_open')) {
            throw new RuntimeException('PHP imap extension is not installed.');
        }

        imap_timeout(IMAP_OPENTIMEOUT, 10);
        imap_timeout(IMAP_READTIMEOUT, 10);

        $connection = @imap_open(
            $this->serverString() . 'INBOX',
            config('imap.username'),
            config('imap.password'),
            retries: 1,
        );

        if ($connection === false) {
            $errors    = imap_errors() ?: [];
            $lastError = imap_last_error() ?: 'Unknown IMAP error';
            $extra     = array_filter($errors, fn($e) => $e !== $lastError);
            $detail    = $lastError . ($extra ? ' | ' . implode(' | ', $extra) : '');
            throw new RuntimeException('IMAP connection failed: ' . $detail);
        }

        return $connection;
    }

    public function testConnection(): void
    {
        $connection = $this->openConnection();
        imap_close($connection);
    }

    public function copy(string $rawMessage): void
    {
        $connection = $this->openConnection();
        $folder     = config('imap.sent_folder', '.Sent');

        try {
            $result = @imap_append(
                $connection,
                $this->serverString() . $folder,
                $rawMessage,
                '\\Seen',
            );

            if (!$result) {
                $error = imap_last_error() ?: 'Unknown IMAP error';
                throw new RuntimeException('IMAP append to sent folder failed: ' . $error);
            }
        } finally {
            imap_close($connection);
        }
    }
}

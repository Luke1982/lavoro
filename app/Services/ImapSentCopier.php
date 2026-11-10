<?php

namespace App\Services;

use App\Models\GeneralSetting;
use IMAP\Connection;
use RuntimeException;

/**
 * Puts a copy of a sent mail in the sender's Sent folder over IMAP: a
 * customer's post in their own mailbox, our invoices in ours. Microsoft 365
 * keeps its own copy and needs none of this.
 */
class ImapSentCopier
{
    /**
     * What a Sent folder goes by, in order of preference. Compared to the last
     * part of the name, so INBOX.Sent and INBOX/Verzonden items count too.
     */
    private const SENT_FOLDER_NAMES = [
        'sent', 'sent items', 'sent messages', 'sent mail',
        'verzonden', 'verzonden items', 'verzonden berichten',
    ];

    public function __construct(
        private readonly ?string $host,
        private readonly int $port,
        private readonly ?string $username,
        private readonly ?string $password,
        private readonly ?string $sent_folder = null,
    ) {}

    /**
     * Whose Sent folder a mail belongs in follows from the mailer that sent it.
     */
    public static function forMailer(?string $mailer): ?self
    {
        return match ($mailer) {
            'landlord' => self::forLandlord(),
            'tenant' => self::forTenant(),
            default => null,
        };
    }

    /**
     * Our own mailbox, unless LANDLORD_IMAP_COPY switches the copy off.
     */
    public static function forLandlord(): ?self
    {
        $imap = config('mail.mailers.landlord.imap');

        if (!$imap['copy']) {
            return null;
        }

        return new self(
            host: $imap['host'],
            port: $imap['port'],
            username: $imap['username'],
            password: $imap['password'],
            sent_folder: $imap['sent_folder'],
        );
    }

    /**
     * The customer's own mailbox, for a customer that sends over SMTP. A
     * server, user name or password left empty under Technisch beheer is the
     * SMTP one, each on its own, as IMAP_* once fell back on MAIL_* in .env.
     */
    public static function forTenant(): ?self
    {
        if (!tenancy()->initialized || GeneralSetting::get('mail_transport') !== 'smtp' || !self::tenantWantsCopies()) {
            return null;
        }

        $setting = fn (string $key): ?string => GeneralSetting::get($key) ?: null;

        return new self(
            host: $setting('mail_imap_host') ?? $setting('mail_smtp_host'),
            port: (int) ($setting('mail_imap_port') ?? 993),
            username: $setting('mail_imap_username') ?? $setting('mail_smtp_username'),
            password: $setting('mail_imap_password') ?? $setting('mail_smtp_password'),
            sent_folder: $setting('mail_imap_sent_folder'),
        );
    }

    /**
     * On unless switched off under Technisch beheer: what a customer sends
     * belongs in their own Sent folder. Off for a server that files its own
     * copy, such as Gmail, or every mail would be there twice.
     */
    public static function tenantWantsCopies(): bool
    {
        return GeneralSetting::get('mail_imap_copy') !== '0';
    }

    public function isConfigured(): bool
    {
        return filled($this->host) && filled($this->username) && filled($this->password);
    }

    public function host(): string
    {
        return (string) $this->host;
    }

    /**
     * Logs in and finds the Sent folder the way a copy will, so a wrong
     * password or a folder the server does not have shows up before the first
     * copy goes missing.
     *
     * @return string the folder a copy would go to
     */
    public function findSentFolder(): string
    {
        return $this->whileConnected(fn (Connection $connection) => $this->decode($this->sentFolder($connection)));
    }

    /**
     * @return string the folder the copy went to
     */
    public function copy(string $raw_message): string
    {
        return $this->whileConnected(function (Connection $connection) use ($raw_message) {
            $folder = $this->sentFolder($connection);

            if (!@imap_append($connection, $this->server() . $folder, $raw_message, '\\Seen')) {
                throw new RuntimeException(
                    'de kopie in ' . $this->decode($folder) . ' zetten mislukt: ' . $this->takeErrors(),
                );
            }

            return $this->decode($folder);
        });
    }

    /**
     * Logged in without opening a folder: listing and appending need none.
     *
     * @template T
     *
     * @param  callable(Connection): T  $work
     * @return T
     */
    private function whileConnected(callable $work): mixed
    {
        if (!function_exists('imap_open')) {
            throw new RuntimeException('de PHP-extensie imap ontbreekt');
        }

        $this->clearErrors();
        imap_timeout(IMAP_OPENTIMEOUT, 10);
        imap_timeout(IMAP_READTIMEOUT, 10);

        $connection = @imap_open(
            $this->server(),
            (string) $this->username,
            (string) $this->password,
            OP_HALFOPEN,
            retries: 1,
        );

        if ($connection === false) {
            throw new RuntimeException('inloggen op ' . $this->host . ' mislukt: ' . $this->takeErrors());
        }

        /**
         * A login that worked can leave errors behind -- an IPv6 address that
         * refused before IPv4 answered -- and those must not be read as what
         * went wrong with what follows.
         */
        $this->clearErrors();

        try {
            return $work($connection);
        } finally {
            imap_close($connection);
            $this->clearErrors();
        }
    }

    /**
     * Port 993 speaks TLS from the first byte; any other port starts plain and
     * has to switch with STARTTLS. On its own, c-client's /tls negotiates that
     * with a TLS 1.0-only method, which OpenSSL 3 refuses before a byte is
     * sent; /tls-sslv23 makes it negotiate the way /ssl does. The certificate
     * is always checked: these are the credentials of somebody's mailbox.
     */
    private function server(): string
    {
        $security = $this->port === 993 ? '/ssl' : '/tls/tls-sslv23';

        return '{' . $this->host . ':' . $this->port . '/imap' . $security . '}';
    }

    /**
     * The Sent folder as the server spells it. One that is set has to exist --
     * opening it would not tell, c-client hands back a connection whether it
     * is there or not. One that is not set is looked up: the least deep match
     * first, then by preference, so a Projecten/Sent never beats a top-level
     * Verzonden items.
     */
    private function sentFolder(Connection $connection): string
    {
        if (filled($this->sent_folder)) {
            $folder = $this->encode($this->sent_folder);

            if (!imap_list($connection, $this->server(), $folder)) {
                $this->failIfServerComplained();

                throw new RuntimeException('de map "' . $this->sent_folder . '" bestaat niet op ' . $this->host);
            }

            return $folder;
        }

        $mailboxes = imap_getmailboxes($connection, $this->server(), '*');

        if ($mailboxes === false) {
            $this->failIfServerComplained();
        }

        $folders = collect($mailboxes ?: [])->map(function (object $mailbox) {
            $name = (string) preg_replace('/^\{[^}]*\}/', '', $mailbox->name);
            $parts = $mailbox->delimiter ? explode($mailbox->delimiter, $name) : [$name];

            return [
                'name' => $name,
                'depth' => count($parts),
                'preference' => array_search(mb_strtolower($this->decode(end($parts))), self::SENT_FOLDER_NAMES, true),
            ];
        });

        $sent = $folders->where('preference', '!==', false)->sortBy([['depth', 'asc'], ['preference', 'asc']])->first();

        if ($sent === null) {
            $seen = $folders->map(fn (array $folder) => $this->decode($folder['name']))->implode(', ');

            throw new RuntimeException('geen map Verzonden gevonden op ' . $this->host . ' tussen '
                . ($seen ?: 'helemaal geen mappen') . '; vul in welke het is');
        }

        return $sent['name'];
    }

    /**
     * An empty answer to LIST means the folder is not there -- or that the
     * server timed out or refused, which c-client only says on its error stack.
     */
    private function failIfServerComplained(): void
    {
        if (imap_last_error()) {
            throw new RuntimeException('mappen opvragen op ' . $this->host . ' mislukt: ' . $this->takeErrors());
        }
    }

    private function encode(string $folder): string
    {
        return mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
    }

    private function decode(string $folder): string
    {
        return mb_convert_encoding($folder, 'UTF-8', 'UTF7-IMAP');
    }

    /**
     * c-client keeps one error stack per process: what one call leaves behind
     * turns up in the next one's message, and whatever is still on it at the
     * end of the request PHP raises as notices. imap_errors() empties the stack
     * and imap_last_error() with it, so the last error is read first.
     */
    private function takeErrors(): string
    {
        $messages = array_unique(array_filter([imap_last_error() ?: null, ...(imap_errors() ?: [])]));
        imap_alerts();

        return implode(' | ', $messages) ?: 'Unknown IMAP error';
    }

    private function clearErrors(): void
    {
        imap_errors();
        imap_alerts();
    }
}

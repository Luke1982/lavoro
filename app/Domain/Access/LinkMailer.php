<?php

namespace App\Domain\Access;

use App\Enums\AccessTokenPurpose;
use App\Mail\AccessLinkMail;
use App\Models\AccessToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Hands someone without an account a link by mail: first the link, then the
 * mail. Whoever calls this records the consequences only once it returns.
 *
 * The mail is sent outside any transaction: an smtp connection takes seconds,
 * and for that long the database would be locked for something that happens
 * entirely outside it. If the mail does not go out, the link is withdrawn and
 * LinkNotSent says so; nothing then promises the customer something they never
 * read.
 */
final class LinkMailer
{
    /**
     * @param  array<string, mixed>  $payload  what this kind of link needs to know
     * @param  callable(string $url, ?string $expires_on): AccessLinkMail  $compose  the mail, around the link
     */
    public function send(
        Model $record,
        AccessTokenPurpose $purpose,
        string $to,
        callable $compose,
        array $payload = [],
        ?\DateTimeInterface $expires_at = null,
    ): IssuedAccessToken {
        $issued = AccessToken::issue($record, $purpose, $to, $payload, $expires_at);

        try {
            Mail::to($to)->send($compose($issued->url(), $issued->token->expires_at?->format('d-m-Y')));
        } catch (Throwable $e) {
            $issued->token->revoke();
            report($e);

            throw new LinkNotSent;
        }

        return $issued;
    }
}

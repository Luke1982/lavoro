<?php

namespace App\Models;

use App\Domain\Access\IssuedAccessToken;
use App\Domain\Access\LinkAlreadyAnswered;
use App\Enums\AccessTokenPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * One link someone without an account may open, for one record and one purpose.
 *
 * The model does not know what the link is about: that is in the morph and in
 * the purpose. Who may do what with it belongs to the screen behind it.
 *
 * @property AccessTokenPurpose $purpose
 */
class AccessToken extends Model
{
    public const TENANT_SEPARATOR = '_';

    /**
     * The hash is left out on purpose: it is set when the link is handed out and
     * must never move through a mass assignment after that.
     */
    protected $fillable = [
        'tokenable_type',
        'tokenable_id',
        'purpose',
        'recipient',
        'payload',
        'expires_at',
    ];

    protected $casts = [
        'purpose' => AccessTokenPurpose::class,
        'payload' => 'array',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * The readable value exists only in what is returned and in the mail sent
     * with it. Only the hash stays behind here.
     *
     * The tenant goes in front, as with the Google webhook: whoever opens the
     * link has no session, so the link itself is the only thing that can say
     * whose database to look in. The hash covers it, so it cannot be swapped
     * for another tenant's.
     *
     * A second link for the same record does not revoke the first: they point
     * at the same thing with the same rights, and breaking a link a customer is
     * looking at that moment gains nothing.
     *
     * The purpose says how long a link lasts, unless the record knows better: a
     * quote's link lasts as long as the quote is valid.
     */
    public static function issue(
        Model $tokenable,
        AccessTokenPurpose $purpose,
        ?string $recipient = null,
        array $payload = [],
        ?\DateTimeInterface $expires_at = null,
    ): IssuedAccessToken {
        $plaintext = tenant()->getTenantKey() . self::TENANT_SEPARATOR . Str::random(48);

        $token = new self([
            'tokenable_type' => $tokenable->getMorphClass(),
            'tokenable_id' => $tokenable->getKey(),
            'purpose' => $purpose,
            'recipient' => $recipient,
            'payload' => $payload,
            'expires_at' => $expires_at ?? now()->addDays($purpose->ttlDays()),
        ]);

        $token->token_hash = self::hash($plaintext);
        $token->created_by_id = Auth::id();
        $token->save();

        return new IssuedAccessToken($token, $plaintext);
    }

    /**
     * Only within the purpose asked for, so a link for one screen does not open
     * another.
     *
     * Revoked is nothing: then there never was anything. Expired does come back,
     * because whoever had the link deserves a sentence explaining why it no
     * longer works.
     */
    public static function resolve(string $plaintext, AccessTokenPurpose $purpose): ?self
    {
        return self::query()
            ->where('token_hash', self::hash($plaintext))
            ->where('purpose', $purpose->value)
            ->whereNull('revoked_at')
            ->first();
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * The tenant a link was handed out for. Split on the last separator: the
     * random part never holds one, a tenant key might. Null for a link from
     * before the tenant was part of it.
     */
    public static function tenantKeyOf(string $plaintext): ?string
    {
        $at = strrpos($plaintext, self::TENANT_SEPARATOR);

        return $at ? substr($plaintext, 0, $at) : null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isUsable(): bool
    {
        return !$this->isRevoked() && !$this->isExpired() && !$this->isUsedUp();
    }

    /** A link that takes one answer and has had it. */
    public function isUsedUp(): bool
    {
        return $this->used_at !== null;
    }

    /**
     * Takes the one answer a link like this gets, and keeps it in the payload.
     * One statement that only succeeds while nobody answered yet, so two tabs
     * sending at the same moment give one answer and one refusal, not two.
     * Inside the caller's transaction, so an answer whose consequences fail
     * leaves the link unanswered.
     *
     * @param  array<string, mixed>  $answer
     *
     * @throws LinkAlreadyAnswered
     */
    public function useUp(array $answer): void
    {
        $now = now();
        $payload = [...($this->payload ?? []), 'answer' => $answer];

        $taken = self::whereKey($this->getKey())
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->update(['used_at' => $now, 'payload' => json_encode($payload), 'updated_at' => $now]);

        if ($taken !== 1) {
            throw new LinkAlreadyAnswered;
        }

        $this->forceFill(['used_at' => $now, 'payload' => $payload])->syncOriginalAttributes(['used_at', 'payload']);
    }

    /** @return array<string, mixed>|null what was answered, for a link that takes one answer */
    public function answer(): ?array
    {
        return $this->payload['answer'] ?? null;
    }

    /**
     * What was answered, when, and where the link had gone: as a record's page
     * lists the answers it got.
     *
     * @return array<string, mixed>
     */
    public function answerSummary(): array
    {
        return [...($this->answer() ?? []), 'answered_at' => $this->used_at, 'recipient' => $this->recipient];
    }

    /**
     * One write: increment takes the rest along, and it is the counter that must
     * not lag behind the moment.
     */
    public function markUsed(): void
    {
        $this->increment('use_count', 1, ['last_used_at' => now()]);
    }

    public function revoke(?User $by = null): void
    {
        $this->forceFill([
            'revoked_at' => now(),
            'revoked_by_id' => $by?->id ?? Auth::id(),
        ])->save();
    }

    /** What is still open: not revoked, not expired and not answered. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->whereNull('used_at')->where('expires_at', '>', now());
    }

    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id')->withTrashed();
    }
}

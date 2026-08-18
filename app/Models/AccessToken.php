<?php

namespace App\Models;

use App\Domain\Access\IssuedAccessToken;
use App\Enums\AccessTokenPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Eén link die iemand zonder account mag openen, voor één record en één doel.
 *
 * Het model weet niet waar de link over gaat: dat staat in de morph en in het
 * doel. Wie er iets mee mag, en wat, hoort bij het scherm erachter.
 *
 * @property AccessTokenPurpose $purpose
 */
class AccessToken extends Model
{
    /**
     * De hash staat er met opzet niet bij: die wordt gezet bij het uitgeven en
     * mag daarna nooit meer door een massa-toewijzing bewegen.
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
        'revoked_at' => 'datetime',
    ];

    /**
     * De leesbare waarde bestaat alleen in de teruggegeven waarde en in de mail
     * die ermee verstuurd wordt. Hier blijft de hash achter.
     *
     * Een tweede link voor hetzelfde record trekt de eerste niet in: ze wijzen
     * naar hetzelfde en dezelfde rechten, en een link stukmaken waar een klant
     * op dat moment naar kijkt levert niets op.
     */
    public static function issue(
        Model $tokenable,
        AccessTokenPurpose $purpose,
        ?string $recipient = null,
        array $payload = [],
    ): IssuedAccessToken {
        $plaintext = Str::random(48);

        $token = new self([
            'tokenable_type' => $tokenable->getMorphClass(),
            'tokenable_id' => $tokenable->getKey(),
            'purpose' => $purpose,
            'recipient' => $recipient,
            'payload' => $payload,
            'expires_at' => now()->addDays($purpose->ttlDays()),
        ]);

        $token->token_hash = self::hash($plaintext);
        $token->created_by_id = Auth::id();
        $token->save();

        return new IssuedAccessToken($token, $plaintext);
    }

    /**
     * Alleen binnen het doel waarvoor gevraagd wordt, zodat een link voor het ene
     * scherm het andere niet opent.
     *
     * Ingetrokken is niets: dan is er nooit iets geweest. Verlopen komt wél
     * terug, want wie de link heeft gehad verdient een zin die uitlegt waarom
     * hij niet meer werkt.
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
        return !$this->isRevoked() && !$this->isExpired();
    }

    /**
     * Eén schrijfopdracht: increment neemt de rest mee, en het is de teller die
     * niet mag achterlopen op het moment.
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

    /** Wat er nog openstaat: niet ingetrokken en niet verlopen. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one conversation has established.
 *
 * @property int $user_id
 * @property string $conversation_id
 * @property array<string, mixed> $facts
 */
class AssistantConversationFact extends Model
{
    protected $fillable = ['user_id', 'conversation_id', 'facts'];

    protected function casts(): array
    {
        return ['facts' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

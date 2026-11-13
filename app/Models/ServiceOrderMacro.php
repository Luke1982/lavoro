<?php

namespace App\Models;

use App\Domain\Macros\MacroDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * A named series of steps run on a werkbon in one go: tasks, remarks,
 * documents, keuringen, with yes/no questions that choose a lane and settings
 * asked when it runs.
 */
class ServiceOrderMacro extends Model
{
    protected $fillable = ['title', 'description', 'definition'];

    protected $casts = [
        'definition' => 'array',
    ];

    public function steps(): MacroDefinition
    {
        return new MacroDefinition($this->definition['steps'] ?? []);
    }

    /**
     * The macro as numbered sentences, the way the assistant reads it.
     *
     * @return array<int, string>
     */
    public function readable(): array
    {
        return $this->steps()->readable();
    }
}

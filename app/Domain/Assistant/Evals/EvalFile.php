<?php

namespace App\Domain\Assistant\Evals;

use App\Domain\Assistant\Contracts\Attachment;
use Closure;
use RuntimeException;

/**
 * A file a scenario sends along with its opening question, named and typed
 * before it exists. Whether a scenario can run here is decided from the name
 * and the type alone; only a run that sends it has it printed.
 */
final class EvalFile
{
    /** @param  Closure(): string  $path  Makes the file when it is not there yet, and says where it is. */
    public function __construct(
        public readonly string $name,
        public readonly string $media_type,
        private readonly Closure $path,
    ) {}

    public function isPicture(): bool
    {
        return str_starts_with($this->media_type, 'image/');
    }

    public function attachment(): Attachment
    {
        $path = ($this->path)();
        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException('Kan ' . $path . ' niet lezen.');
        }

        return new Attachment(name: $this->name, media_type: $this->media_type, base64: base64_encode($content));
    }
}

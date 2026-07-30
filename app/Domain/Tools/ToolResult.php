<?php

namespace App\Domain\Tools;

use App\Domain\Assistant\Contracts\Attachment;

/**
 * What a tool gives back.
 *
 * Two audiences, deliberately separated. `content` is read by the model and
 * should be compact and literal. `summary` is the Dutch line a person reads in
 * the transcript, so it says what happened rather than what was returned.
 *
 * An error is a result, not an exception: the model is told the attempt failed
 * and why, and gets the chance to correct itself.
 */
final class ToolResult
{
    /**
     * @param  array<string, mixed>|string  $content
     */
    /**
     * @param  array<int, Attachment>  $attachments  Files the model should be shown, not told about.
     */
    private function __construct(
        public readonly bool $is_error,
        public readonly array|string $content,
        public readonly ?string $summary = null,
        public readonly array $attachments = [],
    ) {}

    /**
     * @param  array<string, mixed>|string  $content
     */
    public static function ok(array|string $content, ?string $summary = null): self
    {
        return new self(false, $content, $summary);
    }

    /**
     * The same result, with files for the model to read itself.
     *
     * A datasheet is a table of figures and a diagram. Describing one back in
     * prose would be precisely the confident summary this application spent so
     * long teaching the assistant not to produce, so the file goes over whole.
     *
     * @param  array<int, Attachment>  $attachments
     */
    public function showing(array $attachments): self
    {
        return new self($this->is_error, $this->content, $this->summary, $attachments);
    }

    public static function failed(string $reason): self
    {
        return new self(true, $reason, $reason);
    }

    public static function denied(): self
    {
        return self::failed('Je hebt geen rechten voor deze actie.');
    }

    public static function notFound(string $what): self
    {
        return self::failed($what . ' niet gevonden.');
    }

    /**
     * Encoding can fail on text this database genuinely holds — imported customer
     * records carry bytes that are not valid UTF-8 — and a tool that returns a
     * result must not be the thing that throws. Invalid sequences are replaced
     * rather than dropped, so the model sees a usable answer instead of nothing.
     */
    public function toModelContent(): string
    {
        if (is_string($this->content)) {
            return $this->content;
        }

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $encoded = json_encode($this->content, $flags);

        if ($encoded === false) {
            $encoded = json_encode($this->content, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return $encoded === false ? '{"error":"Resultaat kon niet worden gecodeerd."}' : $encoded;
    }
}

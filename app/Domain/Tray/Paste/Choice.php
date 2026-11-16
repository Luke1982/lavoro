<?php

namespace App\Domain\Tray\Paste;

use JsonSerializable;

/**
 * One way a paste can go, with the words on the button that makes it so.
 */
final class Choice implements JsonSerializable
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
        public readonly string $description,
        public readonly string $confirm,
    ) {}

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}

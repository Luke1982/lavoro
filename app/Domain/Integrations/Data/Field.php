<?php

namespace App\Domain\Integrations\Data;

/**
 * One thing a connection needs filled in: a key, a template name, a ledger
 * account. A secret field is stored encrypted and never sent back to the
 * screen; there an empty field means "leave it". $rules are Laravel validation
 * rules on top of "a string": a value that ends up in an address is checked
 * for what it may contain. A scope field names the administration, division or
 * company everything is linked within, and cannot change while anything is.
 */
class Field
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $secret = false,
        public bool $required = true,
        public ?string $help = null,
        public ?string $default = null,
        public array $rules = [],
        public bool $scope = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'secret' => $this->secret,
            'required' => $this->required,
            'help' => $this->help,
            'default' => $this->default,
        ];
    }
}

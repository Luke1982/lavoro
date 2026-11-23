<?php

namespace App\Domain\Pricing;

use App\Models\PriceFormulaVersion;
use App\Support\Money;

/**
 * Contracts moved onto the newest version of their formula: how many, what
 * they cost together per period before and after, and the version they are on
 * now. Moving changes what customers are billed, so it always says so.
 */
final readonly class MovedContracts
{
    public function __construct(
        public int $count,
        public float $before,
        public float $after,
        public ?PriceFormulaVersion $version,
    ) {}

    public static function none(): self
    {
        return new self(0, 0.0, 0.0, null);
    }

    public function notice(): string
    {
        return match ($this->count) {
            0 => 'Er staat geen lopend contract meer op een oudere versie.',
            1 => 'Eén contract bijgewerkt naar ' . $this->version->label() . ': de prijs per periode gaat van '
                . Money::euros($this->before) . ' naar ' . Money::euros($this->after) . '.',
            default => $this->count . ' contracten bijgewerkt naar ' . $this->version->label()
                . ': samen gaan ze per periode van ' . Money::euros($this->before) . ' naar ' . Money::euros($this->after)
                . '. De nieuwe prijs staat op de tijdlijn van elk contract.',
        };
    }
}

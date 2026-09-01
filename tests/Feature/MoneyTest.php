<?php

namespace Tests\Feature;

use App\Support\Money;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Drie vormen die op elkaar lijken en het niet zijn. Ze stonden als losse
 * number_format-aanroepen door de code; wie de ene naar de andere
 * "verbeterde" maakte stil een bestand dat de bank weigert of een invulveld
 * dat niets accepteert.
 */
class MoneyTest extends TestCase
{
    public function test_people_get_a_comma_and_a_thousands_separator(): void
    {
        $this->assertSame('182,50', Money::human(18250));
        $this->assertSame('1.234,50', Money::human(123450));
        $this->assertSame('0,00', Money::human(0));
        $this->assertSame('-27,10', Money::human(-2710));
    }

    /** Een bank of boekhoudpakket leest een punt en geen duizendtal. */
    public function test_machines_get_a_dot_and_nothing_else(): void
    {
        $this->assertSame('182.50', Money::machine(18250));
        $this->assertSame('1234.50', Money::machine(123450));
        $this->assertSame('0.00', Money::machine(0));
    }

    /** Een invulveld weigert een komma, en leeg betekent niet ingesteld. */
    public function test_a_form_field_gets_a_dot_and_keeps_empty_empty(): void
    {
        $this->assertSame('182.50', Money::input(18250));
        $this->assertSame('', Money::input(null));
        $this->assertSame('0.00', Money::input(0), 'Nul is een bedrag, geen leegte.');
    }

    /** Het AI-tegoed staat in miljoensten; een cent is honderd daarvan. */
    public function test_micros_become_cents(): void
    {
        $this->assertSame(2250, Money::fromMicros(22_500_000));
        $this->assertSame(500, Money::fromMicros(5_000_000));
        $this->assertSame(0, Money::fromMicros(0));
    }

    public function test_the_blade_directive_prints_a_sign_and_the_human_form(): void
    {
        $this->assertSame('€ 182,50', trim(Blade::render('@euro(18250)')));
    }
}

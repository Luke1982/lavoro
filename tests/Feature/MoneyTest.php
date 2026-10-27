<?php

namespace Tests\Feature;

use App\Support\Money;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Three shapes that look alike and are not. They sat through the code as loose
 * number_format calls; anyone "improving" the one into the other quietly
 * produced a file the bank refuses or an input field that accepts nothing.
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

    /** A bank or accounting package reads a dot and no thousands separator. */
    public function test_machines_get_a_dot_and_nothing_else(): void
    {
        $this->assertSame('182.50', Money::machine(18250));
        $this->assertSame('1234.50', Money::machine(123450));
        $this->assertSame('0.00', Money::machine(0));
    }

    /** An input field refuses a comma, and empty means not set. */
    public function test_a_form_field_gets_a_dot_and_keeps_empty_empty(): void
    {
        $this->assertSame('182.50', Money::input(18250));
        $this->assertSame('', Money::input(null));
        $this->assertSame('0.00', Money::input(0), 'Nul is een bedrag, geen leegte.');
    }

    /** AI credit is in millionths; a cent is a hundred of them. */
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

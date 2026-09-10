<?php

namespace App\Support\Demo;

/**
 * Product illustrations for the demo catalogue.
 *
 * Drawn rather than photographed: manufacturer photos are not ours to ship, and
 * a demo that fetches them from somewhere at night breaks the first time that
 * somewhere moves. These are clean, consistent catalogue drawings in the brand's
 * colour, with the brand and model underneath -- enough to read as a real
 * catalogue at a glance. A real photo in database/seeders/data/demo/photos wins
 * over the drawing; see CatalogueSeeder.
 *
 * SVG, so no image extension is needed on the server and it stays sharp at any
 * size the screens ask for.
 */
final class ProductArt
{
    private const WIDTH = 800;

    private const HEIGHT = 600;

    public static function render(string $kind, string $brand, string $model, string $accent): string
    {
        $drawing = match ($kind) {
            'wall' => self::wallUnit($accent),
            'cassette' => self::cassette($accent),
            'console' => self::console($accent),
            'ducted' => self::ducted($accent),
            'outdoor' => self::outdoorUnit($accent, 1),
            'outdoor_multi' => self::outdoorUnit($accent, 2),
            'outdoor_large' => self::outdoorLarge($accent),
            'heatpump' => self::heatPump($accent),
            'hydrobox' => self::hydrobox($accent),
            'cabinet' => self::cabinet($accent),
            'cylinder' => self::cylinder($accent),
            'boiler' => self::boiler($accent),
            'hru' => self::heatRecovery($accent),
            'mvbox' => self::ventilationBox($accent),
            'thermostat' => self::thermostat($accent),
            'inverter' => self::inverter($accent),
            'panel' => self::solarPanel(),
            'evaporator' => self::evaporator($accent),
            default => self::cabinet($accent),
        };

        $brand = htmlspecialchars($brand, ENT_XML1);
        $model = htmlspecialchars($model, ENT_XML1);
        $width = self::WIDTH;
        $height = self::HEIGHT;

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$width} {$height}" width="{$width}" height="{$height}">
  <defs>
    <linearGradient id="ground" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#f8fafc"/>
      <stop offset="1" stop-color="#e2e8f0"/>
    </linearGradient>
    <linearGradient id="body" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#ffffff"/>
      <stop offset="1" stop-color="#e5e9ef"/>
    </linearGradient>
    <linearGradient id="metal" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#f1f3f6"/>
      <stop offset="1" stop-color="#cfd6df"/>
    </linearGradient>
    <linearGradient id="dark" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#475569"/>
      <stop offset="1" stop-color="#1e293b"/>
    </linearGradient>
  </defs>
  <rect width="{$width}" height="{$height}" fill="url(#ground)"/>
  <ellipse cx="400" cy="468" rx="250" ry="22" fill="#0f172a" opacity="0.08"/>
  {$drawing}
  <rect x="0" y="512" width="{$width}" height="88" fill="#ffffff"/>
  <rect x="0" y="512" width="8" height="88" fill="{$accent}"/>
  <text x="36" y="550" font-family="Helvetica, Arial, sans-serif" font-size="26" font-weight="700" fill="{$accent}">{$brand}</text>
  <text x="36" y="582" font-family="Helvetica, Arial, sans-serif" font-size="22" fill="#334155">{$model}</text>
</svg>
SVG;
    }

    private static function wallUnit(string $accent): string
    {
        return <<<SVG
  <rect x="150" y="200" width="500" height="150" rx="34" fill="url(#body)" stroke="#cbd5e1" stroke-width="3"/>
  <rect x="180" y="306" width="440" height="22" rx="11" fill="#94a3b8" opacity="0.55"/>
  <rect x="190" y="312" width="420" height="4" rx="2" fill="#475569" opacity="0.5"/>
  <circle cx="600" cy="240" r="6" fill="{$accent}"/>
  <rect x="190" y="228" width="120" height="6" rx="3" fill="#cbd5e1"/>
SVG;
    }

    private static function cassette(string $accent): string
    {
        return <<<SVG
  <rect x="220" y="150" width="360" height="300" rx="20" fill="url(#body)" stroke="#cbd5e1" stroke-width="3"/>
  <rect x="300" y="230" width="200" height="140" rx="12" fill="#e2e8f0" stroke="#cbd5e1" stroke-width="2"/>
  <g stroke="#94a3b8" stroke-width="4" stroke-linecap="round">
    <line x1="320" y1="250" x2="480" y2="250"/><line x1="320" y1="270" x2="480" y2="270"/>
    <line x1="320" y1="290" x2="480" y2="290"/><line x1="320" y1="310" x2="480" y2="310"/>
    <line x1="320" y1="330" x2="480" y2="330"/><line x1="320" y1="350" x2="480" y2="350"/>
  </g>
  <rect x="240" y="170" width="320" height="18" rx="9" fill="#94a3b8" opacity="0.45"/>
  <rect x="240" y="412" width="320" height="18" rx="9" fill="#94a3b8" opacity="0.45"/>
  <circle cx="548" cy="190" r="6" fill="{$accent}"/>
SVG;
    }

    private static function console(string $accent): string
    {
        return <<<SVG
  <rect x="230" y="190" width="340" height="270" rx="26" fill="url(#body)" stroke="#cbd5e1" stroke-width="3"/>
  <rect x="260" y="214" width="280" height="30" rx="10" fill="#94a3b8" opacity="0.5"/>
  <g stroke="#cbd5e1" stroke-width="5" stroke-linecap="round">
    <line x1="270" y1="300" x2="530" y2="300"/><line x1="270" y1="330" x2="530" y2="330"/>
    <line x1="270" y1="360" x2="530" y2="360"/><line x1="270" y1="390" x2="530" y2="390"/>
  </g>
  <circle cx="530" cy="270" r="6" fill="{$accent}"/>
SVG;
    }

    private static function ducted(string $accent): string
    {
        return <<<SVG
  <rect x="140" y="230" width="520" height="160" rx="14" fill="url(#metal)" stroke="#94a3b8" stroke-width="3"/>
  <rect x="100" y="260" width="50" height="100" rx="8" fill="#cbd5e1" stroke="#94a3b8" stroke-width="3"/>
  <rect x="650" y="260" width="50" height="100" rx="8" fill="#cbd5e1" stroke="#94a3b8" stroke-width="3"/>
  <rect x="190" y="262" width="200" height="96" rx="6" fill="#e2e8f0" stroke="#94a3b8" stroke-width="2"/>
  <g stroke="#94a3b8" stroke-width="3"><line x1="200" y1="280" x2="380" y2="280"/><line x1="200" y1="300" x2="380" y2="300"/>
  <line x1="200" y1="320" x2="380" y2="320"/><line x1="200" y1="340" x2="380" y2="340"/></g>
  <rect x="430" y="270" width="190" height="16" rx="8" fill="{$accent}" opacity="0.85"/>
SVG;
    }

    private static function fan(int $cx, int $cy, int $radius): string
    {
        $inner = (int) ($radius * 0.28);
        $blade = (int) ($radius * 0.78);

        return <<<SVG
  <circle cx="{$cx}" cy="{$cy}" r="{$radius}" fill="#334155"/>
  <circle cx="{$cx}" cy="{$cy}" r="{$radius}" fill="none" stroke="#1e293b" stroke-width="6"/>
  <g fill="#64748b">
    <ellipse cx="{$cx}" cy="{$cy}" rx="{$blade}" ry="{$inner}" transform="rotate(20 {$cx} {$cy})"/>
    <ellipse cx="{$cx}" cy="{$cy}" rx="{$blade}" ry="{$inner}" transform="rotate(80 {$cx} {$cy})"/>
    <ellipse cx="{$cx}" cy="{$cy}" rx="{$blade}" ry="{$inner}" transform="rotate(140 {$cx} {$cy})"/>
  </g>
  <circle cx="{$cx}" cy="{$cy}" r="{$inner}" fill="#1e293b"/>
  <g stroke="#94a3b8" stroke-width="3" opacity="0.55" fill="none">
    <circle cx="{$cx}" cy="{$cy}" r="{$radius}"/><circle cx="{$cx}" cy="{$cy}" r="{$blade}"/>
    <line x1="{$cx}" y1="{$cy}" x2="{$cx}" y2="{$cy}"/>
  </g>
SVG;
    }

    private static function outdoorUnit(string $accent, int $fans): string
    {
        if ($fans === 1) {
            $fan = self::fan(340, 320, 108);

            return <<<SVG
  <rect x="180" y="190" width="440" height="270" rx="14" fill="url(#metal)" stroke="#94a3b8" stroke-width="3"/>
  {$fan}
  <g stroke="#94a3b8" stroke-width="3"><line x1="490" y1="220" x2="590" y2="220"/><line x1="490" y1="240" x2="590" y2="240"/>
  <line x1="490" y1="260" x2="590" y2="260"/><line x1="490" y1="280" x2="590" y2="280"/></g>
  <rect x="496" y="400" width="96" height="36" rx="6" fill="#cbd5e1" stroke="#94a3b8" stroke-width="2"/>
  <rect x="180" y="190" width="440" height="12" rx="6" fill="{$accent}" opacity="0.85"/>
  <rect x="210" y="458" width="40" height="14" fill="#64748b"/><rect x="550" y="458" width="40" height="14" fill="#64748b"/>
SVG;
        }

        $top = self::fan(360, 250, 78);
        $bottom = self::fan(360, 400, 78);

        return <<<SVG
  <rect x="220" y="150" width="360" height="320" rx="14" fill="url(#metal)" stroke="#94a3b8" stroke-width="3"/>
  {$top}
  {$bottom}
  <rect x="470" y="400" width="86" height="40" rx="6" fill="#cbd5e1" stroke="#94a3b8" stroke-width="2"/>
  <rect x="220" y="150" width="360" height="12" rx="6" fill="{$accent}" opacity="0.85"/>
SVG;
    }

    private static function outdoorLarge(string $accent): string
    {
        $left = self::fan(310, 205, 70);
        $right = self::fan(490, 205, 70);

        return <<<SVG
  <rect x="190" y="150" width="420" height="320" rx="10" fill="url(#metal)" stroke="#94a3b8" stroke-width="3"/>
  <rect x="200" y="140" width="400" height="130" rx="10" fill="#e2e8f0" stroke="#94a3b8" stroke-width="3"/>
  {$left}
  {$right}
  <g stroke="#94a3b8" stroke-width="3">
    <line x1="210" y1="300" x2="590" y2="300"/><line x1="210" y1="325" x2="590" y2="325"/>
    <line x1="210" y1="350" x2="590" y2="350"/><line x1="210" y1="375" x2="590" y2="375"/>
    <line x1="210" y1="400" x2="590" y2="400"/><line x1="210" y1="425" x2="590" y2="425"/>
  </g>
  <rect x="190" y="450" width="420" height="12" rx="6" fill="{$accent}" opacity="0.85"/>
SVG;
    }

    private static function heatPump(string $accent): string
    {
        $fan = self::fan(330, 320, 112);

        return <<<SVG
  <rect x="160" y="180" width="480" height="280" rx="18" fill="url(#body)" stroke="#cbd5e1" stroke-width="3"/>
  {$fan}
  <rect x="480" y="210" width="130" height="220" rx="12" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="2"/>
  <rect x="500" y="232" width="90" height="10" rx="5" fill="{$accent}"/>
  <g stroke="#cbd5e1" stroke-width="4"><line x1="500" y1="280" x2="590" y2="280"/><line x1="500" y1="300" x2="590" y2="300"/>
  <line x1="500" y1="320" x2="590" y2="320"/></g>
  <rect x="190" y="458" width="46" height="14" fill="#64748b"/><rect x="564" y="458" width="46" height="14" fill="#64748b"/>
SVG;
    }

    private static function hydrobox(string $accent): string
    {
        return <<<SVG
  <rect x="270" y="110" width="260" height="360" rx="22" fill="url(#body)" stroke="#cbd5e1" stroke-width="3"/>
  <rect x="320" y="170" width="160" height="90" rx="10" fill="#0f172a"/>
  <rect x="336" y="186" width="92" height="12" rx="4" fill="{$accent}"/>
  <rect x="336" y="208" width="128" height="8" rx="4" fill="#475569"/><rect x="336" y="224" width="100" height="8" rx="4" fill="#475569"/>
  <circle cx="400" cy="320" r="26" fill="#e2e8f0" stroke="#cbd5e1" stroke-width="3"/>
  <g fill="#94a3b8"><rect x="300" y="470" width="14" height="22"/><rect x="340" y="470" width="14" height="22"/>
  <rect x="446" y="470" width="14" height="22"/><rect x="486" y="470" width="14" height="22"/></g>
SVG;
    }

    private static function cabinet(string $accent): string
    {
        return <<<SVG
  <rect x="280" y="90" width="240" height="380" rx="16" fill="url(#body)" stroke="#cbd5e1" stroke-width="3"/>
  <rect x="280" y="90" width="240" height="70" rx="16" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="3"/>
  <rect x="320" y="112" width="110" height="26" rx="6" fill="#0f172a"/>
  <rect x="330" y="120" width="54" height="10" rx="3" fill="{$accent}"/>
  <line x1="300" y1="300" x2="500" y2="300" stroke="#e2e8f0" stroke-width="4"/>
  <rect x="280" y="440" width="240" height="30" rx="10" fill="{$accent}" opacity="0.18"/>
SVG;
    }

    private static function cylinder(string $accent): string
    {
        return <<<SVG
  <rect x="300" y="110" width="200" height="350" rx="96" fill="url(#body)" stroke="#cbd5e1" stroke-width="3"/>
  <rect x="300" y="200" width="200" height="12" fill="{$accent}" opacity="0.85"/>
  <circle cx="400" cy="280" r="30" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="3"/>
  <line x1="400" y1="280" x2="416" y2="264" stroke="#475569" stroke-width="4" stroke-linecap="round"/>
  <g fill="#94a3b8"><rect x="350" y="456" width="14" height="22"/><rect x="436" y="456" width="14" height="22"/></g>
SVG;
    }

    private static function boiler(string $accent): string
    {
        return <<<SVG
  <rect x="270" y="110" width="260" height="340" rx="18" fill="url(#body)" stroke="#cbd5e1" stroke-width="3"/>
  <rect x="270" y="360" width="260" height="90" rx="18" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="3"/>
  <rect x="316" y="378" width="120" height="40" rx="8" fill="#0f172a"/>
  <text x="330" y="406" font-family="Helvetica, Arial, sans-serif" font-size="24" font-weight="700" fill="{$accent}">1.8</text>
  <circle cx="478" cy="398" r="16" fill="#e2e8f0" stroke="#cbd5e1" stroke-width="3"/>
  <rect x="300" y="150" width="200" height="8" rx="4" fill="{$accent}" opacity="0.8"/>
  <g fill="#94a3b8"><rect x="300" y="448" width="12" height="30"/><rect x="340" y="448" width="12" height="30"/>
  <rect x="448" y="448" width="12" height="30"/><rect x="488" y="448" width="12" height="30"/></g>
SVG;
    }

    private static function heatRecovery(string $accent): string
    {
        return <<<SVG
  <rect x="200" y="170" width="400" height="290" rx="16" fill="url(#body)" stroke="#cbd5e1" stroke-width="3"/>
  <g fill="#e2e8f0" stroke="#94a3b8" stroke-width="3">
    <rect x="230" y="120" width="60" height="56" rx="28"/><rect x="310" y="120" width="60" height="56" rx="28"/>
    <rect x="430" y="120" width="60" height="56" rx="28"/><rect x="510" y="120" width="60" height="56" rx="28"/>
  </g>
  <rect x="250" y="220" width="130" height="36" rx="8" fill="#0f172a"/>
  <rect x="262" y="232" width="60" height="12" rx="4" fill="{$accent}"/>
  <g stroke="#e2e8f0" stroke-width="5"><line x1="240" y1="320" x2="560" y2="320"/><line x1="240" y1="360" x2="560" y2="360"/>
  <line x1="240" y1="400" x2="560" y2="400"/></g>
SVG;
    }

    private static function ventilationBox(string $accent): string
    {
        return <<<SVG
  <rect x="250" y="200" width="300" height="230" rx="14" fill="url(#body)" stroke="#cbd5e1" stroke-width="3"/>
  <g fill="#e2e8f0" stroke="#94a3b8" stroke-width="3">
    <rect x="286" y="150" width="54" height="56" rx="27"/><rect x="372" y="150" width="54" height="56" rx="27"/>
    <rect x="458" y="150" width="54" height="56" rx="27"/>
  </g>
  <circle cx="400" cy="320" r="48" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="3"/>
  <circle cx="400" cy="320" r="10" fill="{$accent}"/>
SVG;
    }

    private static function thermostat(string $accent): string
    {
        return <<<SVG
  <rect x="270" y="150" width="260" height="260" rx="40" fill="url(#body)" stroke="#cbd5e1" stroke-width="3"/>
  <circle cx="400" cy="280" r="96" fill="#0f172a"/>
  <circle cx="400" cy="280" r="96" fill="none" stroke="{$accent}" stroke-width="8" stroke-dasharray="380 230" transform="rotate(130 400 280)"/>
  <text x="400" y="298" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="52" font-weight="300" fill="#ffffff">20.5</text>
  <text x="400" y="330" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="18" fill="#94a3b8">°C</text>
SVG;
    }

    private static function inverter(string $accent): string
    {
        return <<<SVG
  <rect x="260" y="140" width="280" height="320" rx="16" fill="url(#body)" stroke="#cbd5e1" stroke-width="3"/>
  <rect x="300" y="180" width="200" height="60" rx="8" fill="#0f172a"/>
  <rect x="316" y="196" width="80" height="12" rx="4" fill="{$accent}"/>
  <rect x="316" y="216" width="140" height="8" rx="4" fill="#475569"/>
  <g stroke="#cbd5e1" stroke-width="5"><line x1="290" y1="290" x2="510" y2="290"/><line x1="290" y1="320" x2="510" y2="320"/>
  <line x1="290" y1="350" x2="510" y2="350"/><line x1="290" y1="380" x2="510" y2="380"/></g>
  <g fill="#475569"><rect x="310" y="458" width="18" height="18" rx="4"/><rect x="350" y="458" width="18" height="18" rx="4"/>
  <rect x="432" y="458" width="18" height="18" rx="4"/><rect x="472" y="458" width="18" height="18" rx="4"/></g>
SVG;
    }

    private static function solarPanel(): string
    {
        $cells = '';

        for ($row = 0; $row < 6; $row++) {
            for ($column = 0; $column < 4; $column++) {
                $x = 238 + $column * 82;
                $y = 132 + $row * 54;
                $cells .= "<rect x=\"{$x}\" y=\"{$y}\" width=\"76\" height=\"48\" rx=\"3\" fill=\"#1e3a8a\"/>";
            }
        }

        return <<<SVG
  <rect x="226" y="120" width="348" height="346" rx="8" fill="#e2e8f0" stroke="#94a3b8" stroke-width="4"/>
  {$cells}
  <path d="M238 132 L560 132 L420 250 Z" fill="#ffffff" opacity="0.08"/>
SVG;
    }

    private static function evaporator(string $accent): string
    {
        $left = self::fan(320, 300, 58);
        $right = self::fan(480, 300, 58);

        return <<<SVG
  <rect x="170" y="210" width="460" height="180" rx="14" fill="url(#body)" stroke="#cbd5e1" stroke-width="3"/>
  {$left}
  {$right}
  <rect x="170" y="210" width="460" height="14" rx="7" fill="{$accent}" opacity="0.8"/>
  <g stroke="#cbd5e1" stroke-width="3"><line x1="190" y1="370" x2="610" y2="370"/></g>
SVG;
    }
}

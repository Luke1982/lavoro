<?php

namespace App\Support\Demo;

/**
 * Profile pictures for the demo team: flat illustrated portraits.
 *
 * Illustrated and not photographed, for the same reason as the products -- a
 * stock face of a real person is not ours to put on a stranger's account -- and
 * illustrated rather than initials, because a planner full of faces is what
 * makes the screen read as a team instead of a table. A real photo in
 * database/seeders/data/demo/photos/users wins over the drawing.
 *
 * Every trait is chosen per person in the team file, so the same person looks
 * the same every night.
 */
final class Avatars
{
    private const SKIN = [
        'light' => ['#f6d7c3', '#e5b99e'],
        'medium' => ['#e0ac84', '#c98f66'],
        'tan' => ['#c68a5f', '#a86f47'],
        'dark' => ['#8d5a3b', '#6f432a'],
    ];

    /**
     * @param  array{background: string, skin: string, hair: string, hair_color: string, shirt: string, glasses?: bool, beard?: bool}  $look
     */
    public static function render(array $look): string
    {
        [$skin, $shade] = self::SKIN[$look['skin']] ?? self::SKIN['light'];
        $hair_color = $look['hair_color'];
        $shirt = $look['shirt'];
        $background = $look['background'];

        [$behind, $front] = self::hair($look['hair'], $hair_color);
        $beard = !empty($look['beard'])
            ? "<path d=\"M96 150 Q128 196 160 150 Q156 176 128 184 Q100 176 96 150 Z\" fill=\"{$hair_color}\" opacity=\"0.92\"/>"
            : '';
        $glasses = !empty($look['glasses'])
            ? '<g fill="none" stroke="#1f2937" stroke-width="3.5"><circle cx="110" cy="128" r="13"/><circle cx="146" cy="128" r="13"/>'
                . '<line x1="123" y1="128" x2="133" y2="128"/></g>'
            : '';

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="256" height="256">
  <rect width="256" height="256" fill="{$background}"/>
  {$behind}
  <path d="M40 256 Q44 196 128 188 Q212 196 216 256 Z" fill="{$shirt}"/>
  <path d="M104 190 L128 214 L152 190 Z" fill="#ffffff" opacity="0.35"/>
  <rect x="112" y="160" width="32" height="36" rx="12" fill="{$shade}"/>
  <ellipse cx="128" cy="128" rx="44" ry="50" fill="{$skin}"/>
  <ellipse cx="84" cy="132" rx="8" ry="12" fill="{$shade}"/>
  <ellipse cx="172" cy="132" rx="8" ry="12" fill="{$shade}"/>
  <circle cx="110" cy="128" r="4.5" fill="#1f2937"/>
  <circle cx="146" cy="128" r="4.5" fill="#1f2937"/>
  <path d="M100 114 Q110 108 120 113" stroke="{$hair_color}" stroke-width="4" fill="none" stroke-linecap="round"/>
  <path d="M136 113 Q146 108 156 114" stroke="{$hair_color}" stroke-width="4" fill="none" stroke-linecap="round"/>
  <path d="M128 132 Q124 146 130 148" stroke="{$shade}" stroke-width="3" fill="none" stroke-linecap="round"/>
  <path d="M112 158 Q128 170 144 158" stroke="#9a3412" stroke-width="4" fill="none" stroke-linecap="round"/>
  {$beard}
  {$glasses}
  {$front}
</svg>
SVG;
    }

    /**
     * What sits behind the head (long hair) and what sits on top of it.
     *
     * @return array{0: string, 1: string}
     */
    private static function hair(string $style, string $color): array
    {
        return match ($style) {
            'long' => [
                "<path d=\"M74 120 Q70 60 128 56 Q186 60 182 120 L190 212 Q128 228 66 212 Z\" fill=\"{$color}\"/>",
                "<path d=\"M82 118 Q84 70 128 68 Q172 70 174 118 Q160 88 128 86 Q96 88 82 118 Z\" fill=\"{$color}\"/>",
            ],
            'bob' => [
                "<path d=\"M74 118 Q72 62 128 58 Q184 62 182 118 L180 176 Q170 182 162 174 L94 174 Q86 182 76 176 Z\" fill=\"{$color}\"/>",
                "<path d=\"M82 118 Q86 70 128 68 Q170 70 174 118 Q150 92 110 96 Q92 100 82 118 Z\" fill=\"{$color}\"/>",
            ],
            'bun' => [
                "<circle cx=\"128\" cy=\"58\" r=\"22\" fill=\"{$color}\"/>",
                "<path d=\"M82 120 Q84 72 128 70 Q172 72 174 120 Q160 92 128 90 Q96 92 82 120 Z\" fill=\"{$color}\"/>",
            ],
            'curly' => [
                '',
                "<g fill=\"{$color}\"><circle cx=\"94\" cy=\"96\" r=\"18\"/><circle cx=\"116\" cy=\"80\" r=\"20\"/>"
                    . '<circle cx="142" cy="80" r="20"/><circle cx="164" cy="96" r="18"/>'
                    . '<circle cx="84" cy="118" r="12"/><circle cx="172" cy="118" r="12"/></g>',
            ],
            'buzz' => [
                '',
                "<path d=\"M84 116 Q86 74 128 72 Q170 74 172 116 Q168 94 128 92 Q88 94 84 116 Z\" fill=\"{$color}\" opacity=\"0.85\"/>",
            ],
            'bald' => [
                '',
                "<path d=\"M84 128 Q84 110 90 104 L90 132 Z M172 128 Q172 110 166 104 L166 132 Z\" fill=\"{$color}\" opacity=\"0.8\"/>",
            ],
            'side' => [
                '',
                "<path d=\"M82 122 Q80 70 130 66 Q176 68 176 118 Q168 90 140 88 Q120 104 90 106 Q84 112 82 122 Z\" fill=\"{$color}\"/>",
            ],
            default => [
                '',
                "<path d=\"M82 120 Q82 70 128 66 Q174 70 174 120 Q164 88 128 86 Q92 88 82 120 Z\" fill=\"{$color}\"/>",
            ],
        };
    }
}

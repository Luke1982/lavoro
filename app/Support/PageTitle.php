<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * What the browser tab says: "Lavoro - <module> - <tenant>".
 *
 * The module comes from the menu the sidebar is built from, so the tab says
 * what the menu says, and a new screen does not get a name nobody else uses.
 * A detail page belongs to the item it hangs under: /serviceorders/12 is
 * "Werkbonnen".
 */
final class PageTitle
{
    /** Screens a visitor lands on that are not in the menu. */
    private const OUTSIDE_THE_MENU = [
        '/login' => 'Inloggen',
        '/password' => 'Wachtwoord',
        '/storing/informatie' => 'Informatie aanleveren',
    ];

    /** @var array<string, string>|null href => label, longest first */
    private static ?array $modules = null;

    public static function for(Request $request): string
    {
        return implode(' - ', array_filter([
            self::brand(),
            self::module('/' . ltrim($request->path(), '/')),
            tenancy()->initialized ? tenancy()->tenant->name : null,
        ]));
    }

    /**
     * APP_NAME, so a development copy can say "LOCAL Lavoro" and is not taken
     * for production -- except when that is Laravel's own default, which is
     * what stood in every tab on a server whose .env never set it.
     */
    public static function brand(): string
    {
        $name = trim((string) config('app.name'));

        return $name === '' || strcasecmp($name, 'Laravel') === 0 ? 'Lavoro' : $name;
    }

    public static function module(string $path): ?string
    {
        foreach (self::modules() as $href => $label) {
            if ($path === $href || ($href !== '/' && str_starts_with($path, $href . '/'))) {
                return $label;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private static function modules(): array
    {
        if (self::$modules !== null) {
            return self::$modules;
        }

        $menu = json_decode((string) file_get_contents(resource_path('js/Navigation/menu.json')), true);
        $found = self::OUTSIDE_THE_MENU;

        $walk = function (array $items) use (&$walk, &$found): void {
            foreach ($items as $item) {
                if (!empty($item['href'])) {
                    $found[$item['href']] ??= $item['label'];
                }

                $walk($item['children'] ?? []);
            }
        };

        $walk($menu['pinned'] ?? []);

        foreach ($menu['sections'] ?? [] as $section) {
            $walk($section['items'] ?? []);
        }

        uksort($found, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return self::$modules = $found;
    }
}

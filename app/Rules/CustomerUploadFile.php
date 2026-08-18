<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Eén bestand van een klant, afgerekend op wat het is in plaats van op één grens
 * voor alles.
 *
 * De extensie wijst de soort aan en daarmee de grens, maar beslist niets: wat er
 * werkelijk in zit moet erbij passen. Een .exe die foto.jpg heet komt er zo niet
 * langs, en dat is precies het bestand waarvoor deze regel bestaat.
 *
 * De grenzen staan in config/customerupload.php, want de webserver moet ze kunnen
 * volgen.
 */
class CustomerUploadFile implements ValidationRule
{
    /** @var array<int, string> */
    public const KINDS = ['image', 'video', 'document'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value instanceof UploadedFile || !$value->isValid()) {
            $fail('Dit bestand is niet goed aangekomen. Probeer het opnieuw.');

            return;
        }

        $name = $value->getClientOriginalName();
        $kind = self::kindFor($value);

        if ($kind === null) {
            $fail($name . ' kunnen wij niet ontvangen. Stuur een foto, video, pdf of documentbestand.');

            return;
        }

        $max_kb = (int) config('customerupload.' . $kind . '.max_kb');

        if ($max_kb > 0 && $value->getSize() > $max_kb * 1024) {
            $fail($name . ' is te groot. Maximaal ' . self::readableSize($max_kb) . ' per '
                . self::nounFor($kind) . '.');
        }
    }

    /**
     * De soort waar dit bestand onder valt, of null als het nergens bij hoort.
     * Extensie en inhoud moeten het eens zijn: één van de twee is te makkelijk te
     * sturen vanaf de andere kant.
     */
    public static function kindFor(UploadedFile $file): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        foreach (self::KINDS as $kind) {
            $extensions = (array) config('customerupload.' . $kind . '.extensions', []);

            if (in_array($extension, $extensions, true) && self::contentMatches($file, $kind)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Foto's en video's zijn aan hun mediatype te herkennen. Documenten niet: die
     * dragen elk hun eigen type en een lijst daarvan zou naast de lijst met
     * extensies gaan staan en er langzaam van weglopen. Daar telt dat het geen
     * uitvoerbaar bestand is dat zich voordoet als iets anders.
     */
    private static function contentMatches(UploadedFile $file, string $kind): bool
    {
        $mime = strtolower((string) $file->getMimeType());

        return match ($kind) {
            'image' => str_starts_with($mime, 'image/'),
            'video' => str_starts_with($mime, 'video/'),
            default => !str_starts_with($mime, 'image/')
                && !str_starts_with($mime, 'video/')
                && !in_array($mime, [
                    'application/x-msdownload',
                    'application/x-dosexec',
                    'application/x-executable',
                    'application/x-sharedlib',
                    'application/x-mach-binary',
                    'application/vnd.microsoft.portable-executable',
                ], true),
        };
    }

    /** Het woord waarmee de klant over dit soort bestand aangesproken wordt. */
    public static function nounFor(string $kind): string
    {
        return match ($kind) {
            'image' => 'foto',
            'video' => 'video',
            default => 'document',
        };
    }

    private static function readableSize(int $kilobytes): string
    {
        return $kilobytes >= 1024
            ? rtrim(rtrim(number_format($kilobytes / 1024, 1, ',', ''), '0'), ',') . ' MB'
            : $kilobytes . ' KB';
    }
}

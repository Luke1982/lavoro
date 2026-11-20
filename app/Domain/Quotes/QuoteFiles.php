<?php

namespace App\Domain\Quotes;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The files that go with a quote: the quote on paper, and the general terms it
 * is offered under, as they were stored when that version was made. They go
 * along with the mail, which is the proof the terms were handed over before
 * signing, and open in the browser from the quote and from the customer's link.
 *
 * A file is held in memory as its name, its contents and its type.
 */
final class QuoteFiles
{
    /**
     * What a mail about this quote carries.
     *
     * @param  array<string, mixed>  $document  as QuoteForCustomer gives it
     * @param  array<string, mixed>|null  $signed  the yes, for the signed copy
     * @return array<int, array{name: string, data: string, mime: string}>
     */
    public static function forMail(array $document, ?array $signed = null): array
    {
        return array_values(array_filter([self::pdf($document, $signed), self::terms($document)]));
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>|null  $signed
     * @return array{name: string, data: string, mime: string}
     */
    public static function pdf(array $document, ?array $signed = null): array
    {
        return [
            'name' => QuotePdf::filename($document, signed: $signed !== null),
            'data' => QuotePdf::render($document, $signed),
            'mime' => 'application/pdf',
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{name: string, data: string, mime: string}|null
     */
    public static function terms(array $document): ?array
    {
        $terms = $document['terms'] ?? null;
        $disk = Storage::disk('local');

        return $terms && $disk->exists($terms['path'])
            ? ['name' => $terms['name'], 'data' => $disk->get($terms['path']), 'mime' => 'application/pdf']
            : null;
    }

    /**
     * A file to open in the browser, under its own name. The name of the terms
     * is what someone uploaded, so it is put in the header the safe way.
     *
     * @param  array{name: string, data: string, mime: string}  $file
     */
    public static function inline(array $file): Response
    {
        return new Response($file['data'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $file['name'],
                Str::ascii(str_replace(['/', '\\', '%'], '-', $file['name'])),
            ),
        ]);
    }
}

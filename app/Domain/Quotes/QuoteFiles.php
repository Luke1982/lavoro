<?php

namespace App\Domain\Quotes;

use App\Enums\QuoteTermsKinds;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The files that go with a quote: the quote on paper, and the general terms it
 * is offered under, as they were when that version was made. They go along with
 * the mail, which is the proof the terms were handed over before signing, and
 * open in the browser from the quote and from the customer's link.
 *
 * Terms written as a text are handed over as a PDF of that text, so the
 * customer has them to keep just like an uploaded file. Terms kept on a page of
 * the company's own are not a file: they open that page.
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
        return array_values(array_filter([self::pdf($document, $signed), self::terms($document['terms'] ?? null)]));
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
     * The terms as a file: the uploaded PDF, or the written text made into one.
     * A version from before there was a choice holds an uploaded PDF.
     *
     * @param  array<string, mixed>|null  $terms  as QuoteSettings::terms() gives them
     * @return array{name: string, data: string, mime: string}|null
     */
    public static function terms(?array $terms): ?array
    {
        return match ($terms === null ? null : QuoteTermsKinds::tryFrom($terms['kind'] ?? 'pdf')) {
            QuoteTermsKinds::pdf => self::uploaded($terms),
            QuoteTermsKinds::text => [
                'name' => $terms['name'],
                'data' => QuotePdf::renderTerms($terms['html']),
                'mime' => 'application/pdf',
            ],
            default => null,
        };
    }

    /**
     * @param  array{name: string, path: string}  $terms
     * @return array{name: string, data: string, mime: string}|null
     */
    private static function uploaded(array $terms): ?array
    {
        $disk = Storage::disk('local');

        return $disk->exists($terms['path'])
            ? ['name' => $terms['name'], 'data' => $disk->get($terms['path']), 'mime' => 'application/pdf']
            : null;
    }

    /** The terms in the browser: the file, or the page they are kept on. */
    public static function open(?array $terms): Response
    {
        if (($terms['kind'] ?? null) === QuoteTermsKinds::link->value) {
            return new RedirectResponse($terms['url']);
        }

        $file = self::terms($terms);

        abort_if($file === null, 404);

        return self::inline($file);
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

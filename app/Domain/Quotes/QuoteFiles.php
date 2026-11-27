<?php

namespace App\Domain\Quotes;

use App\Enums\QuoteTermsKinds;
use App\Exceptions\Refusal;
use App\Models\Document;
use App\Models\Image;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The files that go with a quote: the quote on paper, and the general terms it
 * is offered under, as they were when that version was made. They go along with
 * the mail, which is the proof the terms were handed over before signing, and
 * open in the browser from the quote and from the customer's link. Sending the
 * quote, and reminding the customer of it, also takes along the documents and
 * photos shared from the quote with the customer.
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
     * What the mail that sends the quote carries: the files of forMail() and
     * what was shared with the customer, as long as a mail can carry it all.
     *
     * @param  array<string, mixed>  $document  as QuoteForCustomer gives it
     * @return array<int, array{name: string, data: string, mime: string}>
     */
    public static function forSending(array $document): array
    {
        $files = [...self::forMail($document), ...self::sharedFiles($document['attachments'] ?? [])];
        $bytes = array_sum(array_map(fn (array $file) => strlen($file['data']), $files));
        $limit_mb = config('mail.max_attachments_mb');

        if ($bytes > $limit_mb * 1024 * 1024) {
            throw new Refusal(
                'De offerte gaat met ' . number_format($bytes / 1024 / 1024, 1, ',', '') . ' MB aan bestanden mee, en een mail kan er '
                . $limit_mb . ' MB dragen. Deel minder of kleinere bijlagen met de klant.'
            );
        }

        return $files;
    }

    /**
     * The documents and photos shared from the quote with the customer, as a
     * version remembers them: which one, and the name the customer gets it
     * under. A reminder sends what the version names, not what is shared now.
     *
     * @return array<int, array{kind: string, id: int, name: string}>
     */
    public static function shared(Quote $quote): array
    {
        $shared_here = fn (Builder $shares) => $shares
            ->where('source_type', $quote->getMorphClass())
            ->where('source_id', $quote->getKey());

        $documents = $quote->documents()->whereHas('shares', $shared_here)->orderBy('documents.id')->get()
            ->map(fn (Document $document) => ['kind' => 'document', 'id' => $document->id, 'name' => $document->name]);
        $images = $quote->images()->whereHas('shares', $shared_here)->orderBy('images.id')->get()
            ->map(fn (Image $image) => ['kind' => 'image', 'id' => $image->id, 'name' => self::filename($image->name, $image->path)]);

        return [...$documents, ...$images];
    }

    /**
     * The shared files themselves. One removed since the version was sent is left out.
     *
     * @param  array<int, array{kind: string, id: int, name: string}>  $attachments
     * @return array<int, array{name: string, data: string, mime: string}>
     */
    private static function sharedFiles(array $attachments): array
    {
        $disk = Storage::disk('public');

        return collect($attachments)
            ->map(fn (array $attachment) => [
                'name' => $attachment['name'],
                'path' => ($attachment['kind'] === 'image' ? Image::class : Document::class)::find($attachment['id'])?->path,
            ])
            ->filter(fn (array $file) => $file['path'] !== null && $disk->exists($file['path']))
            ->map(fn (array $file) => [
                'name' => $file['name'],
                'data' => $disk->get($file['path']),
                'mime' => $disk->mimeType($file['path']) ?: 'application/octet-stream',
            ])
            ->values()
            ->all();
    }

    /** A photo's name as a file name: with the extension of the file it is stored in. */
    private static function filename(string $name, string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension === '' || str_ends_with(strtolower($name), '.' . strtolower($extension))
            ? $name
            : $name . '.' . $extension;
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

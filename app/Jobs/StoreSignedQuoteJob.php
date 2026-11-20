<?php

namespace App\Jobs;

use App\Domain\Quotes\QuoteFiles;
use App\Domain\Quotes\QuoteMail;
use App\Domain\Quotes\QuotePdf;
use App\Mail\QuoteConfirmationMail;
use App\Models\Document;
use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Makes the signed copy of an accepted quote: the quote as it was accepted, with
 * the name, the signature and what the customer agreed to, filed under the
 * quote's documents and mailed to the customer with the terms. Mailed to where
 * the answered link had gone, or otherwise to the quote's usual address.
 *
 * A quote that was taken back to concept before this ran has nothing signed
 * left to keep.
 */
class StoreSignedQuoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Quote $quote, public ?string $recipient) {}

    public function handle(): void
    {
        $signed = QuotePdf::signed($this->quote);

        if ($signed === null) {
            return;
        }

        $files = QuoteFiles::forMail($this->quote->signedDocument(), $signed);
        $this->file($files[0]);

        $to = $this->recipient ?? QuoteMail::defaultRecipient($this->quote);

        if ($to !== null) {
            Mail::to($to)->send(new QuoteConfirmationMail(
                QuoteMail::confirmationSubject($this->quote),
                QuoteMail::confirmationBody($this->quote),
                $files,
            ));
        }
    }

    /**
     * Once per yes: a retry after the mail failed finds the copy already filed.
     *
     * @param  array{name: string, data: string, mime: string}  $pdf
     */
    private function file(array $pdf): void
    {
        $filed = $this->quote->documents()
            ->where('name', $pdf['name'])
            ->where('documents.created_at', '>=', $this->quote->decided_at)
            ->exists();

        if ($filed) {
            return;
        }

        $path = 'uploaded/quote/' . $this->quote->id . '/documents/' . Str::uuid() . '.pdf';
        Storage::disk('public')->put($path, $pdf['data']);

        $document = Document::create([
            'name' => $pdf['name'],
            'path' => $path,
            'size' => strlen($pdf['data']),
            'title' => pathinfo($pdf['name'], PATHINFO_FILENAME),
            'document_category_id' => null,
            'user_id' => null,
        ]);

        $this->quote->documents()->attach($document->id, ['internal' => false]);
    }
}

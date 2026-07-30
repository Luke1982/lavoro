<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Contracts\Attachment;
use App\Models\Asset;
use App\Models\Document;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Finds the documentation attached to a product, and reads it out for the model.
 *
 * Two filters that both matter. Only categories whose name reads like
 * documentation, because a contract or an invoice on a product is somebody's
 * commercial paperwork and has no business being sent to a supplier to answer a
 * question about refrigerant. And only PDFs, because that is what can actually
 * be read — an .odt would be sent as bytes nobody can interpret.
 */
class ProductDocumentation
{
    /**
     * The documentation for a product, as files ready to travel with a question.
     *
     * @return Collection<int, Attachment>
     */
    public function for(Product $product, User $user): Collection
    {
        return $this->fromDocuments($this->documentationOn($product, $user));
    }

    /**
     * The same, for the product a machine happens to be.
     *
     * Somebody standing in front of a machine asking how to bleed it wants the
     * manual for the model, which is filed against the product. Documents on the
     * machine itself come too: those are the ones about this one in particular.
     *
     * @return Collection<int, Attachment>
     */
    public function forAsset(Asset $asset, User $user): Collection
    {
        /**
         * Machines hold no documents of their own in this application — there is
         * no relation and no panel for it — so everything comes from the product.
         * Should they ever gain one, this is where it joins.
         */
        if (!$asset->product) {
            return collect();
        }

        return $this->fromDocuments($this->documentationOn($asset->product, $user));
    }

    /** @return Collection<int, Document> */
    private function documentationOn(Product $product, User $user): Collection
    {
        if (!Product::visibleTo($user)->whereKey($product->id)->exists()) {
            return collect();
        }

        return $this->documentationFrom($product);
    }

    /** @return Collection<int, Document> */
    private function documentationFrom(mixed $model): Collection
    {
        return $model->documents()
            ->with('category:id,name')
            ->get()
            ->filter(fn (Document $document) => $this->readsAsDocumentation($document))
            ->values();
    }

    /**
     * Whether a category name reads like documentation.
     *
     * There is no column that says so — categories are named by whoever set the
     * system up — so the name is matched against a configured list of words. A
     * document with no category at all is left out: unfiled is not the same as
     * documentation, and guessing in that direction is how an invoice ends up
     * being sent to a supplier.
     */
    private function readsAsDocumentation(Document $document): bool
    {
        $name = $document->category?->name;

        if (blank($name)) {
            return false;
        }

        foreach ((array) config('assistant.documentation_categories', []) as $word) {
            if (str_contains(mb_strtolower($name), mb_strtolower((string) $word))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Document>  $documents
     * @return Collection<int, Attachment>
     */
    private function fromDocuments(Collection $documents): Collection
    {
        $max_bytes = (int) config('assistant.max_document_kilobytes', 8192) * 1024;

        /**
         * A budget across all of them, not merely a limit on each. Three files
         * each inside the per-file limit came to exactly the supplier's ceiling
         * once base64 had added its third — before the prompt, the tools and the
         * conversation were counted. The whole request would have been refused,
         * and the question with it.
         */
        $budget = (int) config('assistant.max_payload_kilobytes', 16384) * 1024;
        $spent = 0;
        $attachments = collect();

        $candidates = $documents
            ->filter(fn (Document $document) => $this->isPdf($document) && (int) $document->size <= $max_bytes)
            ->take((int) config('assistant.max_documents_per_question', 3));

        foreach ($candidates as $document) {
            $attachment = $this->read($document);

            if ($attachment === null) {
                continue;
            }

            $size = strlen($attachment->base64);

            if ($spent + $size > $budget) {
                Log::info('Document overgeslagen: het verzoek zou te groot worden', [
                    'document' => $document->id,
                    'spent_bytes' => $spent,
                ]);

                continue;
            }

            $spent += $size;
            $attachments->push($attachment);
        }

        return $attachments->values();
    }

    private function isPdf(Document $document): bool
    {
        return str_ends_with(mb_strtolower((string) $document->path), '.pdf');
    }

    /**
     * A file that has gone missing from the disk must not take the question with
     * it. The rest of the answer is still worth having.
     */
    private function read(Document $document): ?Attachment
    {
        try {
            if (!Storage::exists($document->path)) {
                return null;
            }

            $contents = Storage::get($document->path);
        } catch (\Throwable $e) {
            Log::warning('Kon document niet lezen voor de assistent', [
                'document' => $document->id,
                'exception' => $e,
            ]);

            return null;
        }

        if ($contents === null || $contents === '') {
            return null;
        }

        return new Attachment(
            name: $document->title ?: $document->name ?: ('document-' . $document->id . '.pdf'),
            media_type: 'application/pdf',
            base64: base64_encode($contents),
        );
    }
}

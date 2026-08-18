<?php

namespace App\Services;

use App\Domain\Tickets\CustomerUploadResult;
use App\Jobs\DownscaleVideoJob;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Image;
use App\Models\Remark;
use App\Models\Ticket;
use App\Rules\CustomerUploadFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Neemt aan wat een klant instuurt en hangt het aan de storing.
 *
 * Foto's worden meteen verkleind, want dat duurt een tel en de klant wacht toch
 * al op het antwoord. Video's gaan onverkleind de opslag in en worden daarna in
 * een taak omgezet: minuten wachten op een omzetting is geen antwoord.
 *
 * Namen worden hier opnieuw bedacht. Wat er binnenkomt is door iemand van buiten
 * getypt, en dat mag niet bepalen waar een bestand terechtkomt of wat het
 * overschrijft; de oorspronkelijke naam blijft wel staan als naam van het record,
 * want daar herkent de klant het aan.
 */
class CustomerUploadIntake
{
    public function __construct(private MediaDownscaler $downscaler) {}

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function receive(Ticket $ticket, array $files, ?string $note, ?string $author_name): CustomerUploadResult
    {
        $result = new CustomerUploadResult;

        foreach ($files as $file) {
            $kind = CustomerUploadFile::kindFor($file);

            match ($kind) {
                'image' => $this->receiveImage($ticket, $file, $result),
                'video', 'document' => $this->receiveDocument($ticket, $file, $kind, $result),
                default => null,
            };
        }

        if (filled($note)) {
            $this->receiveNote($ticket, $note, $author_name);
            $result->has_note = true;
            $result->entries[] = ['name' => 'Toelichting', 'kind' => 'note', 'at' => now()->toIso8601String()];
        }

        return $result;
    }

    private function receiveImage(Ticket $ticket, UploadedFile $file, CustomerUploadResult $result): void
    {
        $disk = Storage::disk('public');
        $directory = $this->directoryFor($ticket);
        $stored = $disk->putFileAs($directory, $file, $this->safeName($file));

        /** Verkleinen kan het bestand hernoemen (heic wordt jpg), dus het pad komt terug. */
        $final = $this->downscaler->image($disk->path($stored));
        $path = $directory . '/' . basename($final);

        $image = Image::create([
            'name' => $file->getClientOriginalName(),
            'path' => $path,
        ]);

        $ticket->images()->attach($image->id, ['internal' => false]);

        $result->photos++;
        $result->first_photo_path ??= $path;
        $result->first_photo_id ??= $image->id;
        $result->entries[] = [
            'name' => $file->getClientOriginalName(),
            'kind' => 'image',
            'at' => now()->toIso8601String(),
        ];
    }

    private function receiveDocument(
        Ticket $ticket,
        UploadedFile $file,
        string $kind,
        CustomerUploadResult $result,
    ): void {
        $directory = $this->directoryFor($ticket) . '/documents';
        $path = Storage::disk('public')->putFileAs($directory, $file, $this->safeName($file));

        $document = Document::create([
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'size' => $file->getSize(),
            'title' => $this->titleFor($file),
            'document_category_id' => $this->category()->id,
            'user_id' => null,
        ]);

        $ticket->documents()->attach($document->id, ['internal' => false]);

        if ($kind === 'video') {
            /**
             * Na de commit, want de taak leest de rij die hier nog geschreven wordt.
             * Met de database-wachtrij zou het toevallig goed gaan; dit blijft ook
             * goed gaan als er ooit een andere achter gezet wordt.
             */
            DownscaleVideoJob::dispatch($document->id)->afterCommit();
            $result->videos++;
        } else {
            $result->documents++;
        }

        $result->entries[] = [
            'name' => $file->getClientOriginalName(),
            'kind' => $kind,
            'at' => now()->toIso8601String(),
        ];
    }

    private function receiveNote(Ticket $ticket, string $note, ?string $author_name): void
    {
        $remark = Remark::create([
            'user_id' => null,
            'author_name' => $author_name ?: 'Klant',
            'content' => $note,
        ]);

        $ticket->remarks()->attach($remark->id, ['internal' => false]);
    }

    /** Dezelfde map als waar het personeel zijn foto's kwijtraakt: één storing, één plek. */
    private function directoryFor(Ticket $ticket): string
    {
        return 'uploaded/ticket/' . $ticket->id;
    }

    /**
     * Een naam die niets kan omzeilen en niets kan overschrijven: een eigen
     * voorvoegsel, een geschoonde naam en de oorspronkelijke extensie.
     */
    private function safeName(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension()) ?: 'bin';
        $stem = Str::limit(Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)), 60, '');

        return Str::random(8) . '-' . ($stem !== '' ? $stem : 'bestand') . '.' . $extension;
    }

    private function titleFor(UploadedFile $file): string
    {
        $stem = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        return Str::limit(ucfirst(str_replace(['-', '_'], ' ', $stem)), 120);
    }

    private function category(): DocumentCategory
    {
        return DocumentCategory::firstOrCreate(
            ['name' => (string) config('customerupload.document_category', 'Klantinformatie')],
            ['color' => 'purple', 'order' => 99],
        );
    }
}

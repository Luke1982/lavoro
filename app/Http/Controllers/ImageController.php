<?php

namespace App\Http\Controllers;

use App\Actions\Images\AttachImagesAction;
use App\Actions\Images\RemoveImageAction;
use App\Actions\Shares\ShareRecordAction;
use App\Domain\Signals\Attachments\ImageAnnotated;
use App\Domain\Signals\Attachments\ImageRenamed;
use App\Domain\Signals\Attachments\ImageReplaced;
use App\Domain\Signals\Attachments\MainImageChanged;
use App\Domain\Signals\Signal;
use App\Domain\Signals\Signals;
use App\Http\Requests\ImageAnnotationsReadRequest;
use App\Http\Requests\ImageDestroyRequest;
use App\Http\Requests\ImageImportFromUrlRequest;
use App\Http\Requests\ImageSetMainRequest;
use App\Http\Requests\ImageShareRequest;
use App\Http\Requests\ImageStoreRequest;
use App\Http\Requests\ImageUpdateRequest;
use App\Models\Image;
use App\Models\Share;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ImageStoreRequest $request, AttachImagesAction $attach)
    {
        /**
         * @disregard
         */
        if (!$request->hasFile('images')) {
            return redirect()->back()->withErrors(['error' => 'No images were uploaded.']);
        }

        $record_class = Image::attachableType($request->imageable_type);
        $imageable_record = $record_class::findOrFail($request->imageable_id);
        $directory = AttachImagesAction::folderFor($imageable_record);

        /**
         * The client's filename neither names the file on disk nor keys its title:
         * iOS calls every camera capture "image.jpg", so each photo would replace the last.
         *
         * @disregard
         */
        $uploads = collect($request->file('images'))->map(fn (UploadedFile $image, int $index) => [
            'name' => $request->input('titles.' . $index) ?? $image->getClientOriginalName(),
            'path' => $image->storePublicly($directory, 'public'),
        ]);

        $created_images = $attach->execute($imageable_record, $uploads->values()->all(), $request->boolean('internal', false));

        if ($request->wantsJson()) {
            return response()->json($created_images, 201);
        }

        return redirect()->back()->with([
            'success' => 'Afbeelding(en) opgeslagen.',
            'extra' => json_encode($created_images, true),
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ImageUpdateRequest $request, Image $image)
    {
        if ($request->hasFile('imageToUpdate')) {
            $this->replaceFile($image, $request->file('imageToUpdate'), $request->validated('annotations'));
        }

        if ($request->filled('newTitle')) {
            $this->rename($image, $request->newTitle);
        }

        if ($request->wantsJson()) {
            return response()->json($image);
        }

        return redirect()->back()->with(
            [
                'success' => 'Afbeelding bijgewerkt.',
                'extra' => json_encode($image, true),
            ]
        );
    }

    /**
     * A file that comes with a drawing is that drawing burned into the photo, so the
     * photo as it was taken stays behind for the next edit. One without is a new photo.
     */
    private function replaceFile(Image $image, UploadedFile $file, ?array $annotations): void
    {
        $stored_path = $file->storePublicly(dirname($image->path), 'public');
        abort_if($stored_path === false, 500, 'De afbeelding kon niet worden opgeslagen.');

        if ($annotations === null) {
            $image->update(['path' => $stored_path, 'original_path' => null, 'annotations' => null]);

            $this->announceOnItsRecord($image, fn (Model $record) => new ImageReplaced($record, $image));

            return;
        }

        $image->update([
            'original_path' => $image->unannotatedPath(),
            'path' => $stored_path,
            'annotations' => $annotations,
        ]);

        $this->announceOnItsRecord($image, fn (Model $record) => new ImageAnnotated($record, $image));
    }

    private function rename(Image $image, string $name): void
    {
        $previous_name = $image->name;
        $image->update(['name' => $name]);

        if ($image->wasChanged('name')) {
            $this->announceOnItsRecord($image, fn (Model $record) => new ImageRenamed($record, $image, $previous_name));
        }
    }

    /**
     * Tells what happened to a photo on the record it hangs off, read from the
     * photo's own link. A photo that hangs off nothing has nobody to tell.
     *
     * @param  Closure(Model): Signal  $signal
     */
    private function announceOnItsRecord(Image $image, Closure $signal): void
    {
        $record = $image->attachedRecord();

        if ($record !== null) {
            Signals::dispatch($signal($record));
        }
    }

    public function annotations(ImageAnnotationsReadRequest $request, Image $image)
    {
        return response()->json(['annotations' => $image->annotations]);
    }

    public function destroy(ImageDestroyRequest $request, Image $image, RemoveImageAction $remove)
    {
        $remove->execute($image);

        if ($request->wantsJson()) {
            return response()->json(['deleted' => true]);
        }

        return redirect()->back()->with('success', 'Afbeelding verwijderd.');
    }

    public function setMain(ImageSetMainRequest $request, Image $image)
    {
        $this->makeMain(
            Image::attachableType($request->imageable_type),
            (int) $request->imageable_id,
            $request->boolean('currently_main') ? null : $image,
        );

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->back()->with('success', 'Hoofdafbeelding bijgewerkt.');
    }

    /**
     * Makes this photo the record's main photo, or leaves it without one. The main
     * photo is a flag on the link, which no model event hears, so the change is
     * announced on the record when another photo ends up main.
     *
     * @param  class-string<Model>  $record_class
     */
    private function makeMain(string $record_class, int $record_id, ?Image $image): void
    {
        DB::transaction(function () use ($record_class, $record_id, $image) {
            $previous_id = $this->mainImageId($record_class, $record_id);

            $this->links($record_class, $record_id)->update(['main' => false]);

            if ($image !== null) {
                $this->links($record_class, $record_id)->where('image_id', $image->id)->update(['main' => true]);
            }

            $current_id = $this->mainImageId($record_class, $record_id);
            $record = $previous_id !== $current_id ? $record_class::find($record_id) : null;

            if ($record !== null) {
                Signals::dispatch(new MainImageChanged(
                    $record,
                    $previous_id !== null ? Image::find($previous_id) : null,
                    $current_id !== null ? Image::find($current_id) : null,
                ));
            }
        });
    }

    /** @param  class-string<Model>  $record_class */
    private function mainImageId(string $record_class, int $record_id): ?int
    {
        $image_id = $this->links($record_class, $record_id)->where('main', true)->value('image_id');

        return $image_id === null ? null : (int) $image_id;
    }

    /**
     * The links from the record to its photos, where the main photo is a flag.
     *
     * @param  class-string<Model>  $record_class
     */
    private function links(string $record_class, int $record_id): Builder
    {
        return DB::table('imageables')
            ->where('imageable_type', (new $record_class)->getMorphClass())
            ->where('imageable_id', $record_id);
    }

    /**
     * Shares the photo from the record it hangs on, read from the photo's own link
     * rather than from whatever record the screen says it is showing.
     */
    public function share(ImageShareRequest $request, Image $image, ShareRecordAction $share_record)
    {
        $shared = $request->boolean('shared');
        $source = $image->attachedRecord();

        $share_record->execute($image, $source, $shared);

        if ($request->wantsJson()) {
            return response()->json(['shared' => $shared]);
        }

        return redirect()->back()->with('success', $shared
            ? 'Afbeelding gedeeld met ' . Share::audience($source) . '.'
            : 'Afbeelding niet meer gedeeld met ' . Share::audience($source) . '.');
    }

    public function importFromUrl(ImageImportFromUrlRequest $request, AttachImagesAction $attach)
    {
        $url = $request->url;
        $record_class = Image::attachableType($request->imageable_type);
        $imageable_record = $record_class::findOrFail($request->imageable_id);
        $name = $request->name ?? 'Geïmporteerde afbeelding';

        $extension_map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        if (str_starts_with($url, 'data:image/')) {
            if (!preg_match('/^data:(image\/(?:jpeg|png|gif|webp));base64,([A-Za-z0-9+\/=]+)$/', $url, $matches)) {
                return redirect()->back()->withErrors(['url' => 'Ongeldige base64 afbeelding.']);
            }
            $mime = $matches[1];
            $extension = $extension_map[$mime] ?? 'jpg';
            $image_data = base64_decode($matches[2], strict: true);
            if ($image_data === false) {
                return redirect()->back()->withErrors(['url' => 'Base64 data kon niet worden gedecodeerd.']);
            }
        } else {
            $this->guardSsrf($url);

            $response = Http::timeout(15)->get($url);

            if (!$response->successful()) {
                return redirect()->back()->withErrors(['url' => 'De afbeelding kon niet worden gedownload.']);
            }

            $content_type = $response->header('Content-Type') ?? '';
            $mime = strtolower(explode(';', $content_type)[0]);

            if (!array_key_exists($mime, $extension_map)) {
                return redirect()->back()->withErrors(['url' => 'De URL verwijst niet naar een afbeelding.']);
            }

            $extension = $extension_map[$mime];
            $image_data = $response->body();
        }

        $path = AttachImagesAction::folderFor($imageable_record) . '/import-' . Str::random(40) . '.' . $extension;
        Storage::disk('public')->put($path, $image_data);

        [$new_image] = $attach->execute($imageable_record, [['name' => $name, 'path' => $path]]);

        $this->makeMain($record_class, $imageable_record->id, $new_image);

        return redirect()->back()->with('success', 'Afbeelding geïmporteerd en ingesteld als hoofdafbeelding.');
    }

    private function guardSsrf(string $url): void
    {
        $parsed = parse_url($url);

        if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'])) {
            abort(422, 'Ongeldige URL.');
        }

        $host = $parsed['host'] ?? '';
        $ip = gethostbyname($host);

        $private_ranges = [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '::1/128',
            'fc00::/7',
        ];

        foreach ($private_ranges as $range) {
            if ($this->ipInRange($ip, $range)) {
                abort(422, 'URL verwijst naar een niet-toegestaan netwerk.');
            }
        }
    }

    private function ipInRange(string $ip, string $range): bool
    {
        if (!str_contains($range, '/')) {
            return $ip === $range;
        }

        [$subnet, $bits] = explode('/', $range);
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);

        if ($ip_long === false || $subnet_long === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ip_long & $mask) === ($subnet_long & $mask);
    }
}

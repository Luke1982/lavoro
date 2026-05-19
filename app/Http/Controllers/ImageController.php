<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImageDestroyRequest;
use App\Http\Requests\ImageImportFromUrlRequest;
use App\Http\Requests\ImageSetMainRequest;
use App\Http\Requests\ImageStoreRequest;
use App\Models\Image;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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
    public function store(ImageStoreRequest $request)
    {
        /**
         * @disregard
         */
        if (!$request->hasFile('images')) {
            return redirect()->back()->withErrors(['error' => 'No images were uploaded.']);
        }

        $imageable_record = new ($request->imageable_type);
        $imageable_record = $imageable_record->find($request->imageable_id);
        $array            = explode('\\', $request->imageable_type);
        $modelname        = strtolower(array_pop($array));
        $created_images   = [];
        /**
         * @disregard
         */
        foreach ($request->file('images') as $image) {
            $path      = 'uploaded/' . $modelname . '/' . $request->imageable_id . '/';
            $real_path = storage_path('app/' . $path);

            // Ensure the directory exists with proper permissions
            if (!file_exists($real_path)) {
                mkdir($real_path, 0755, true);
            }
            $image->storePubliclyAs($path, $image->getClientOriginalName(), 'public');
            $new_image = Image::create([
                'name' => $request->titles[$image->getClientOriginalName()],
                'path' => $path . $image->getClientOriginalName(),
            ]);
            $imageable_record->images()->attach($new_image->id);
            $created_images[] = $new_image;
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
    public function update(ImageStoreRequest $request, Image $image)
    {
        /**
         * @disregard
         */
        if ($request->hasFile('imageToUpdate')) {
            $time = time();
            /**
             * @disregard P1013
             */
            $new_image_file = $request->file('imageToUpdate');
            Storage::delete($image->path);

            $path_segments = explode('/', $image->path);
            array_pop($path_segments);
            $store_dir = (implode('/', $path_segments));

            list($filename, $extension) = preg_split('/\./', $new_image_file->getClientOriginalName(), 2);
            $filename = preg_replace('/-TS\d{10}/', '', $filename);
            $new_filename = $filename . '-TS' . $time . '.' . $extension;

            $new_image_file->storeAs($store_dir, $new_filename, 'public');

            $image->update([
                'updated_at' => now(),
                'path' => $store_dir . '/' . $new_filename
            ]);
        }
        /**
         * @disregard
         */
        if ($request->has('newTitle') && $request->newTitle !== null) {
            /**
             * @disregard
             */
            $image->update([
                'name' => $request->newTitle,
            ]);
        }
        return redirect()->back()->with(
            [
                'success' => 'Afbeelding bijgewerkt.',
                'extra' => json_encode($image, true),
            ]
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ImageDestroyRequest $request, Image $image)
    {
        $imageable_record = new ($request->imageable_type);
        $imageable_record = $imageable_record->find($request->imageable_id);
        $imageable_record->images()->detach($image->id);
        $image->delete();
        Storage::delete($image->path);
        return redirect()->back()->with('success', 'Afbeelding verwijderd.');
    }

    public function setMain(ImageSetMainRequest $request, Image $image)
    {
        $imageable_type = (new ($request->imageable_type))->getMorphClass();
        $imageable_id   = (int) $request->imageable_id;

        DB::table('imageables')
            ->where('imageable_type', $imageable_type)
            ->where('imageable_id', $imageable_id)
            ->update(['main' => false]);

        if (!$request->boolean('currently_main')) {
            DB::table('imageables')
                ->where('image_id', $image->id)
                ->where('imageable_type', $imageable_type)
                ->where('imageable_id', $imageable_id)
                ->update(['main' => true]);
        }

        return redirect()->back()->with('success', 'Hoofdafbeelding bijgewerkt.');
    }

    public function importFromUrl(ImageImportFromUrlRequest $request)
    {
        $url            = $request->url;
        $imageable_type = $request->imageable_type;
        $imageable_id   = (int) $request->imageable_id;
        $name           = $request->name ?? 'Geïmporteerde afbeelding';

        $extension_map = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];

        if (str_starts_with($url, 'data:image/')) {
            if (!preg_match('/^data:(image\/(?:jpeg|png|gif|webp));base64,([A-Za-z0-9+\/=]+)$/', $url, $matches)) {
                return redirect()->back()->withErrors(['url' => 'Ongeldige base64 afbeelding.']);
            }
            $mime      = $matches[1];
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
            $mime         = strtolower(explode(';', $content_type)[0]);

            if (!array_key_exists($mime, $extension_map)) {
                return redirect()->back()->withErrors(['url' => 'De URL verwijst niet naar een afbeelding.']);
            }

            $extension  = $extension_map[$mime];
            $image_data = $response->body();
        }

        $array     = explode('\\', $imageable_type);
        $modelname = strtolower(array_pop($array));
        $path      = 'uploaded/' . $modelname . '/' . $imageable_id . '/';
        $real_path = storage_path('app/public/' . $path);

        if (!file_exists($real_path)) {
            mkdir($real_path, 0755, true);
        }

        $filename = 'import-' . time() . '.' . $extension;
        file_put_contents($real_path . $filename, $image_data);

        $new_image = Image::create([
            'name' => $name,
            'path' => $path . $filename,
        ]);

        $imageable_record = new ($imageable_type);
        $imageable_record = $imageable_record->find($imageable_id);
        $imageable_record->images()->attach($new_image->id);

        DB::table('imageables')
            ->where('imageable_type', $imageable_type)
            ->where('imageable_id', $imageable_id)
            ->where('image_id', '!=', $new_image->id)
            ->update(['main' => false]);

        DB::table('imageables')
            ->where('image_id', $new_image->id)
            ->where('imageable_type', $imageable_type)
            ->where('imageable_id', $imageable_id)
            ->update(['main' => true]);

        return redirect()->back()->with('success', 'Afbeelding geïmporteerd en ingesteld als hoofdafbeelding.');
    }

    private function guardSsrf(string $url): void
    {
        $parsed = parse_url($url);

        if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'])) {
            abort(422, 'Ongeldige URL.');
        }

        $host = $parsed['host'] ?? '';
        $ip   = gethostbyname($host);

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
        $ip_long     = ip2long($ip);
        $subnet_long = ip2long($subnet);

        if ($ip_long === false || $subnet_long === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);
        return ($ip_long & $mask) === ($subnet_long & $mask);
    }
}

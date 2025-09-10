<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImageStoreRequest;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Http\Requests\StoreImageRequest;

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
    public function destroy(Image $image, Request $request)
    {
        $imageable_record = new ($request->imageable_type);
        $imageable_record = $imageable_record->find($request->imageable_id);
        $imageable_record->images()->detach($image->id);
        $image->delete();
        Storage::delete($image->path);
        return redirect()->back()->with('success', 'Afbeelding verwijderd.');
    }
}

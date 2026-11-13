<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Image;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function image(Image $image)
    {
        return $this->servePublicFile($image->path);
    }

    public function imageOriginal(Image $image)
    {
        return $this->servePublicFile($image->unannotatedPath());
    }

    public function avatar(User $user)
    {
        $directory = "users/{$user->id}/avatar";
        $files = Storage::disk('public')->files($directory);
        abort_if(empty($files), 404);

        return Storage::disk('public')->response($files[0]);
    }

    public function companyLogo(Company $company, string $variant = 'main')
    {
        return $this->servePublicFile($variant === 'negative' ? $company->logo_negative_path : $company->logo_path);
    }

    private function servePublicFile(?string $path): StreamedResponse
    {
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path);
    }
}

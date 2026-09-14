<?php

namespace Tests\Feature\Images;

use App\Models\Image;
use App\Models\Product;
use App\Models\User;
use App\Services\UserAvatarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * iOS calls every camera capture "image.jpg". Stored under that name, the second
 * photo on a record replaced the first on disk, and the browser kept showing the
 * old one from its cache.
 */
class PhotosKeepTheirOwnFileTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->product = Product::factory()->create();
    }

    public function test_two_camera_captures_on_one_record_keep_both_photos(): void
    {
        $first = UploadedFile::fake()->image('image.jpg', 10, 10);
        $second = UploadedFile::fake()->image('image.jpg', 40, 40);

        $this->upload([$first])->assertCreated();
        $this->upload([$second])->assertCreated();

        [$kept_first, $kept_second] = $this->product->images()->orderBy('images.id')->get();

        $this->assertNotSame($kept_first->path, $kept_second->path);
        $this->assertSame(file_get_contents($first->getRealPath()), Storage::disk('public')->get($kept_first->path));
        $this->assertSame(file_get_contents($second->getRealPath()), Storage::disk('public')->get($kept_second->path));
    }

    public function test_a_photo_lands_in_the_folder_of_its_record_under_a_generated_name(): void
    {
        $this->upload([UploadedFile::fake()->image('image.jpg')])->assertCreated();

        $image = Image::sole();

        $this->assertSame('image.jpg', $image->name);
        $this->assertMatchesRegularExpression('#^uploaded/product/' . $this->product->id . '/[A-Za-z0-9]{40}\.jpg$#', $image->path);
        Storage::disk('public')->assertExists($image->path);
    }

    public function test_titles_follow_their_photo_when_the_file_names_collide(): void
    {
        $this->upload(
            [UploadedFile::fake()->image('image.jpg'), UploadedFile::fake()->image('image.jpg')],
            ['Voorkant', 'Achterkant'],
        )->assertCreated();

        $this->assertSame(['Voorkant', 'Achterkant'], $this->product->images()->orderBy('images.id')->pluck('name')->all());
    }

    public function test_an_empty_title_falls_back_to_the_file_name(): void
    {
        $this->upload([UploadedFile::fake()->image('image.jpg')], [''])->assertCreated();

        $this->assertSame('image.jpg', Image::sole()->name);
    }

    public function test_a_title_longer_than_the_column_is_refused(): void
    {
        $this->upload([UploadedFile::fake()->image('image.jpg')], [str_repeat('x', 256)])
            ->assertJsonValidationErrors('titles.0');

        $this->assertSame(0, Image::count());
    }

    public function test_the_api_route_keeps_both_photos_too(): void
    {
        $this->actingAs($this->admin())->postJson('/api/images', [
            'images' => [UploadedFile::fake()->image('image.jpg'), UploadedFile::fake()->image('image.jpg')],
            'imageable_type' => Product::class,
            'imageable_id' => $this->product->id,
        ])->assertCreated();

        $this->assertCount(2, Image::pluck('path')->unique());
    }

    public function test_a_new_avatar_replaces_the_old_one_under_a_new_name(): void
    {
        $user = User::factory()->create();
        $directory = 'users/' . $user->id . '/avatar';

        app(UserAvatarService::class)->save($user, UploadedFile::fake()->image('image.jpg'));
        $first = Storage::disk('public')->files($directory);
        app(UserAvatarService::class)->save($user, UploadedFile::fake()->image('image.jpg'));
        $second = Storage::disk('public')->files($directory);

        $this->assertCount(1, $second);
        $this->assertNotSame($first, $second);
    }

    private function upload(array $images, array $titles = []): TestResponse
    {
        return $this->actingAs($this->admin())->postJson(route('images.store'), [
            'images' => $images,
            'titles' => $titles,
            'imageable_type' => Product::class,
            'imageable_id' => $this->product->id,
        ]);
    }
}

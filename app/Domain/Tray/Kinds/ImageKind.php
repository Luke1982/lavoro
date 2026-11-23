<?php

namespace App\Domain\Tray\Kinds;

use App\Domain\Tray\Paste\ImagesOntoPhotos;
use App\Models\Asset;
use App\Models\Image;
use App\Models\Material;
use App\Models\Product;
use App\Models\Project;
use App\Models\ServiceCheckInstance;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Photos on the bakje, to paste as copies among the photos of another record, or
 * of the same record on its other side: from the internal photos of a werkbon to
 * the ones the customer sees.
 *
 * A photo has no customer of its own: it is the customer of whatever it hangs off.
 * Which record that is, and on which side, is read along with the photo.
 */
final class ImageKind extends TrayKind
{
    /** The pages the records a photo hangs off are shown on. */
    private const PAGES = [
        Asset::class => '/assets/',
        Material::class => '/materials/',
        Product::class => '/products/',
        Project::class => '/projects/',
        ServiceOrder::class => '/serviceorders/',
        Ticket::class => '/tickets/',
    ];

    public static function name(): string
    {
        return 'image';
    }

    public static function model(): string
    {
        return Image::class;
    }

    public static function one(): string
    {
        return 'foto';
    }

    public static function many(): string
    {
        return "foto's";
    }

    public static function places(): array
    {
        return [
            'photos' => ImagesOntoPhotos::class,
        ];
    }

    /** The photos this person may look at, hanging off a record they may open. */
    public static function visibleTo(User $user): Builder
    {
        $query = Image::query()->select('images.*')->addSelect([
            'tray_record_type' => self::firstLink('imageable_type'),
            'tray_record_id' => self::firstLink('imageable_id'),
            'tray_internal' => self::firstLink('internal'),
        ]);

        if ($user->cannot('viewAny', Image::class)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($user) {
            foreach (Image::ATTACHABLE_TO as $class) {
                $query->orWhereExists(fn (QueryBuilder $links) => self::linksTo($links, $class, $user));
            }
        });
    }

    /**
     * The records of this class whose photos this person may reach: the ones its
     * own rule lets them see, a keurpunt through its werkbon, and every one of a
     * record without such a rule, such as a material, as on its own screen.
     *
     * @param  class-string<Model>  $class
     */
    public static function recordsVisibleTo(string $class, User $user): Builder
    {
        return match (true) {
            $class === ServiceCheckInstance::class => ServiceCheckInstance::whereHas('serviceJob', fn (Builder $jobs) => $jobs
                ->whereIn('service_order_id', ServiceOrder::visibleTo($user)->select('service_orders.id'))),
            method_exists($class, 'scopeVisibleTo') => $class::visibleTo($user),
            default => $class::query(),
        };
    }

    /** How a record photos hang off is named in a sentence: "Werkbon WB-0012", 'Keurpunt "Lekdicht"'. */
    public static function recordLabel(Model $record): string
    {
        return $record instanceof ServiceCheckInstance
            ? 'Keurpunt "' . ($record->serviceCheck?->name ?? 'onbekend') . '"'
            : (string) $record->historyLabel();
    }

    /** @param  Image  $image */
    public static function title(Model $image): string
    {
        return $image->name ?: 'Foto #' . $image->id;
    }

    /**
     * What it hangs off, and on which side: "Werkbon WB-0012 · intern".
     *
     * @param  Image  $image
     */
    public static function subtitle(Model $image): ?string
    {
        $record = self::record($image);

        if ($record === null) {
            return null;
        }

        return self::recordLabel($record) . ($image->tray_internal ? ' · intern' : '');
    }

    /** @param  Image  $image */
    public static function customerId(Model $image): ?int
    {
        $record = self::record($image);

        return match (true) {
            $record instanceof Asset => $record->resolvedCustomerId(),
            $record instanceof Ticket => $record->asset?->resolvedCustomerId(),
            $record instanceof ServiceCheckInstance => $record->serviceJob?->serviceOrder?->customer_id,
            default => $record?->getAttribute('customer_id'),
        };
    }

    /**
     * Opening it goes to the page of what it hangs off: a keurpunt's is its werkbon.
     * Without a page of its own, such as an appointment, it opens the photo itself.
     *
     * @param  Image  $image
     */
    protected static function card(Model $image): array
    {
        $file = '/files/images/' . $image->id;
        $record = self::record($image);

        $page = match (true) {
            $record instanceof ServiceCheckInstance => '/serviceorders/' . $record->serviceJob?->service_order_id,
            $record !== null && isset(self::PAGES[$record::class]) => self::PAGES[$record::class] . $record->getKey(),
            default => $file,
        };

        return ['thumbnail' => $file, 'url' => $page];
    }

    /**
     * The record this photo hangs off, if it still exists. Kept on the photo, so
     * describing it asks once.
     */
    private static function record(Image $image): ?Model
    {
        if (!$image->relationLoaded('trayRecord')) {
            $class = Image::attachableType($image->tray_record_type);
            $image->setRelation('trayRecord', $class === null ? null : $class::find($image->tray_record_id));
        }

        return $image->getRelation('trayRecord');
    }

    /**
     * The links from a photo to records of this class that this person may reach.
     *
     * @param  class-string<Model>  $class
     */
    private static function linksTo(QueryBuilder $links, string $class, User $user): QueryBuilder
    {
        $model = new $class;

        return $links->from('imageables')
            ->whereColumn('imageables.image_id', 'images.id')
            ->where('imageables.imageable_type', $model->getMorphClass())
            ->whereIn('imageables.imageable_id', self::recordsVisibleTo($class, $user)->select($model->getQualifiedKeyName()));
    }

    /** One column of the first link a photo has: older photos could hang off more than one record. */
    private static function firstLink(string $column): QueryBuilder
    {
        return DB::table('imageables')
            ->select($column)
            ->whereColumn('imageables.image_id', 'images.id')
            ->orderBy('imageables.id')
            ->limit(1);
    }
}

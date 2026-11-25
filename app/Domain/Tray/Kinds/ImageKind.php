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

    /**
     * The photos this person may look at: Image::visibleTo, the same rule as opening
     * one. Whoever may not read projects also gets the werkbon a project photo is
     * shared on, which it is shown on instead (shownOn).
     */
    public static function visibleTo(User $user): Builder
    {
        $query = Image::visibleTo($user)->select('images.*')->addSelect([
            'tray_record_type' => self::firstLink('imageable_type'),
            'tray_record_id' => self::firstLink('imageable_id'),
            'tray_internal' => self::firstLink('internal'),
        ]);

        if ($user->cannot('viewAny', Project::class)) {
            $query->addSelect(['tray_order_id' => self::werkbonSharedOn($user)]);
        }

        return $query;
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
        $record = self::shownOn($image);

        if ($record === null) {
            return null;
        }

        return Image::labelOf($record) . (self::internal($image) ? ' · intern' : '');
    }

    /** Whether it lies among the internal photos of what it hangs off. */
    public static function internal(Image $image): bool
    {
        return (bool) $image->tray_internal;
    }

    /** @param  Image  $image */
    public static function customerId(Model $image): ?int
    {
        $record = self::shownOn($image);

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
        $record = self::shownOn($image);

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
    public static function source(Image $image): ?Model
    {
        if (!$image->relationLoaded('trayRecord')) {
            $class = Image::attachableType($image->tray_record_type);
            $image->setRelation('trayRecord', $class === null ? null : $class::find($image->tray_record_id));
        }

        return $image->getRelation('trayRecord');
    }

    /**
     * Where the photo is shown to whoever listed it: what it hangs off, or for a
     * project photo reached through a werkbon by someone who may not read projects,
     * that werkbon, so neither the page it opens nor its name is the project's.
     * Nothing when there is no such werkbon.
     */
    public static function shownOn(Image $image): ?Model
    {
        if (!$image->relationLoaded('trayShownOn')) {
            $record = self::source($image);
            $through_werkbon = $record instanceof Project && array_key_exists('tray_order_id', $image->getAttributes());

            $image->setRelation('trayShownOn', match (true) {
                !$through_werkbon => $record,
                $image->tray_order_id === null => null,
                default => ServiceOrder::find($image->tray_order_id),
            });
        }

        return $image->getRelation('trayShownOn');
    }

    /** The first werkbon this person may open that the photo is shared on from its project. */
    private static function werkbonSharedOn(User $user): Builder
    {
        return ServiceOrder::visibleTo($user)
            ->select('service_orders.id')
            ->whereIn('service_orders.project_id', fn (QueryBuilder $shares) => $shares->from('shares')
                ->select('shares.source_id')
                ->whereColumn('shares.shareable_id', 'images.id')
                ->where('shares.shareable_type', (new Image)->getMorphClass())
                ->where('shares.source_type', (new Project)->getMorphClass()))
            ->orderBy('service_orders.id')
            ->limit(1);
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

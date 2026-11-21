<?php

namespace App\Models;

use App\Models\Traits\HasActivities;
use App\Models\Traits\HasCustomFields;
use App\Models\Traits\RecordsHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasActivities;
    use HasCustomFields;
    use HasFactory;
    use RecordsHistory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'mobile',
        'website',
        'contact_person',
        'address',
        'postal_code',
        'city',
        'country',
        'iban',
        'vat_number',
        'kvk_number',
    ];

    protected array $activity_labels = [
        'name' => 'Naam',
        'email' => 'E-mail',
        'phone' => 'Telefoon',
        'mobile' => 'Mobiel',
        'website' => 'Website',
        'contact_person' => 'Contactpersoon',
        'address' => 'Adres',
        'postal_code' => 'Postcode',
        'city' => 'Plaats',
        'country' => 'Land',
        'iban' => 'IBAN',
        'vat_number' => 'BTW-nummer',
        'kvk_number' => 'KVK-nummer',
    ];

    /**
     * The products and materials it was linked to at the moment it was deleted.
     * The database drops those links along with it, so they are read just before.
     *
     * @var array<int, Model>
     */
    private array $linked_before_delete = [];

    protected static function booted(): void
    {
        static::deleting(function (Supplier $supplier): void {
            $supplier->linked_before_delete = [
                ...$supplier->products()->get(['products.id'])->all(),
                ...$supplier->materials()->get(['materials.id'])->all(),
            ];
        });
    }

    /**
     * A supplier that is deleted takes its own timeline with it, and its links to
     * products and materials: the products and materials it supplied say so.
     */
    public function historyTimelines(string $action): array
    {
        return $action === 'deleted' ? $this->linked_before_delete : [];
    }

    public function historyLabel(): ?string
    {
        return 'Leverancier ' . $this->name;
    }

    public function products()
    {
        return $this->morphedByMany(Product::class, 'suppliable')
            ->withPivot('article_number', 'is_preferred')
            ->withTimestamps();
    }

    public function materials()
    {
        return $this->morphedByMany(Material::class, 'suppliable')
            ->withPivot('article_number', 'is_preferred')
            ->withTimestamps();
    }
}

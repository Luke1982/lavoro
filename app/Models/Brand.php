<?php

namespace App\Models;

use App\Models\Traits\RecordsHistory;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    use RecordsHistory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
    ];

    protected array $activity_labels = [
        'name' => 'Naam',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * A brand has no page of its own, but its name is half of every product's: a
     * rename is told on each of its products, in one go. Its creation and removal
     * concern no product (it cannot go while it has any), so they land nowhere.
     */
    public function historyTimelines(string $action): array
    {
        return $action === 'updated' ? $this->products()->get(['id'])->all() : [];
    }

    public function historyLabel(): ?string
    {
        return 'Merk ' . $this->name;
    }
}

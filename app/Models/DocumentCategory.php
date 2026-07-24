<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentCategory extends Model
{
    use HasFactory;

    /**
     * Named colors the frontend can render as literal Tailwind classes. A free
     * hex would have to be applied inline, so the palette stays closed.
     */
    public const COLORS = ['blue', 'green', 'amber', 'red', 'purple', 'gray'];

    protected $fillable = [
        'name',
        'color',
        'order',
    ];

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    /**
     * The list DocumentUploadComponent renders its tabs and pickers from. Pages
     * that show the widget hand this to Inertia themselves, so the query never
     * runs for the pages that don't.
     */
    public static function forPicker()
    {
        return static::orderBy('order')->orderBy('name')->get(['id', 'name', 'color']);
    }
}

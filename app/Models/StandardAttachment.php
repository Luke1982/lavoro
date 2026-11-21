<?php

namespace App\Models;

use App\Models\Traits\HasActivities;
use App\Models\Traits\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A file that goes along with standard e-mails. Its file is removed with it (DeleteStandardAttachmentFile). */
class StandardAttachment extends Model
{
    use HasActivities;
    use RecordsHistory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'path',
        'original_filename',
        'mime_type',
        'size',
    ];

    protected array $activity_labels = [
        'name' => 'Naam',
        'original_filename' => 'Bestandsnaam',
    ];

    /** Where and how the file is stored follows from the upload; the file name says what it is. */
    protected array $activity_ignore = [
        'path',
        'mime_type',
        'size',
    ];

    public function standardEmails(): BelongsToMany
    {
        return $this->belongsToMany(StandardEmail::class, 'standard_email_standard_attachment')
            ->withTimestamps();
    }
}

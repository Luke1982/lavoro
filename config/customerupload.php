<?php

/**
 * Wat een klant via een aanleverlink mag versturen, en hoe groot het daarna nog
 * is.
 *
 * De grenzen staan hier en niet in een Form Request omdat de webserver ze moet
 * kunnen volgen: PHP laat standaard 2 MB per bestand door, dus wat hier hoger
 * staat vraagt om upload_max_filesize, post_max_size en client_max_body_size die
 * minstens even ruim staan. Niets hiervan is op een ontwikkelmachine gemeten.
 */
return [
    /** Hoe lang een uitgegeven link werkt, in dagen. */
    'token_days' => (int) env('CUSTOMER_UPLOAD_TOKEN_DAYS', 14),

    'max_files' => (int) env('CUSTOMER_UPLOAD_MAX_FILES', 10),

    'note_max' => (int) env('CUSTOMER_UPLOAD_NOTE_MAX', 5000),

    'image' => [
        'max_kb' => (int) env('CUSTOMER_UPLOAD_IMAGE_MAX_KB', 25600),
        'extensions' => ['jpg', 'jpeg', 'png', 'heic', 'heif', 'webp', 'gif'],

        /** De langste zijde na verkleinen. Kleiner dan dit blijft zoals het is. */
        'max_edge' => (int) env('CUSTOMER_UPLOAD_IMAGE_MAX_EDGE', 1920),
        'quality' => (int) env('CUSTOMER_UPLOAD_IMAGE_QUALITY', 82),
    ],

    'video' => [
        'max_kb' => (int) env('CUSTOMER_UPLOAD_VIDEO_MAX_KB', 204800),
        'extensions' => ['mp4', 'mov', 'm4v', '3gp', 'webm', 'avi', 'mkv'],
        'max_height' => (int) env('CUSTOMER_UPLOAD_VIDEO_MAX_HEIGHT', 720),
        'crf' => (int) env('CUSTOMER_UPLOAD_VIDEO_CRF', 28),
        'audio_bitrate' => env('CUSTOMER_UPLOAD_VIDEO_AUDIO_BITRATE', '128k'),

        /** Een verkleining die niets oplevert wordt weggegooid. */
        'preset' => env('CUSTOMER_UPLOAD_VIDEO_PRESET', 'medium'),
    ],

    'document' => [
        'max_kb' => (int) env('CUSTOMER_UPLOAD_DOCUMENT_MAX_KB', 51200),
        'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'odt', 'ods'],
    ],

    /** De categorie waaronder wat de klant stuurt bij de documenten belandt. */
    'document_category' => env('CUSTOMER_UPLOAD_DOCUMENT_CATEGORY', 'Klantinformatie'),

    'ffmpeg' => env('FFMPEG_PATH', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_PATH', 'ffprobe'),

    /** Seconden die een omzetting mag duren voordat hij wordt afgebroken. */
    'ffmpeg_timeout' => (int) env('FFMPEG_TIMEOUT', 900),
];

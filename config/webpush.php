<?php

return [
    /**
     * The application's VAPID identity: one keypair, generated once with
     * `php artisan notifications:vapid-keys` and then left alone. Changing the
     * public key invalidates every browser subscription ever handed out, because
     * each one was signed for the key that was current when it was made.
     */
    'public_key' => env('VAPID_PUBLIC_KEY'),

    'private_key' => env('VAPID_PRIVATE_KEY'),

    /**
     * How a push service can reach whoever runs this installation, which they may
     * do when something is wrong with what is being sent. A mailto: or an https:
     * URL, and required by the spec, so the app URL stands in.
     */
    'subject' => env('VAPID_SUBJECT', env('APP_URL')),

    /**
     * How long a push service should keep trying to deliver. A day: long enough
     * to survive a phone that spent the night in a van, short enough that nobody
     * is woken by a storing that has since been closed.
     */
    'ttl' => (int) env('VAPID_TTL', 86400),
];

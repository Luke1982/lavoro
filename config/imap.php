<?php

return [
    'host'          => env('IMAP_HOST', env('MAIL_HOST')),
    'port'          => (int) env('IMAP_PORT', 993),
    'encryption'    => env('IMAP_ENCRYPTION', 'ssl'), // ssl | tls | none
    'validate_cert' => env('IMAP_VALIDATE_CERT', false),
    'username'      => env('IMAP_USERNAME', env('MAIL_USERNAME')),
    'password'      => env('IMAP_PASSWORD', env('MAIL_PASSWORD')),
    'sent_folder'   => env('IMAP_SENT_FOLDER', 'Sent'),
];

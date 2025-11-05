<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Disks to encrypt
    |--------------------------------------------------------------------------
    |
    | List filesystem disk names that should have their image contents
    | encrypted at rest. For example: ['s3', 'public']
    |
    */
    'encrypted_disks' => [
        's3',
        'public',
    ],

    /*
    |--------------------------------------------------------------------------
    | Thumbnails encryption
    |--------------------------------------------------------------------------
    |
    | If true, thumbnails will also be encrypted. Default false because
    | unencrypted thumbnails can be cached and are faster to serve.
    */
    'encrypt_thumbnails' => false,
];

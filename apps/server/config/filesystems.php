<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Conversation message attachments (ADR 0007). A private, never-served
        // local disk: no `url`, no `serve`, private visibility. Files are only
        // ever reached by streaming through an authorized Wayfindr endpoint —
        // there is no public path, guessable URL, or storage link. This is the
        // local-first surface and the default.
        'attachments' => [
            'driver' => 'local',
            'root' => storage_path('app/private/attachments'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        // The remote attachment surface (ADR 0007): any S3-compatible object
        // store (AWS S3, MinIO, Cloudflare R2, Backblaze B2, DigitalOcean
        // Spaces, or GCS via its S3-interop endpoint). It inherits the exact
        // same authorization boundary as the local disk — downloads still
        // stream through the app (no `url`, no storage URL ever handed to the
        // client), and the bucket itself must stay private.
        'attachments-s3' => [
            'driver' => 's3',
            'key' => env('WAYFINDR_ATTACHMENT_S3_KEY'),
            'secret' => env('WAYFINDR_ATTACHMENT_S3_SECRET'),
            'region' => env('WAYFINDR_ATTACHMENT_S3_REGION', 'us-east-1'),
            'bucket' => env('WAYFINDR_ATTACHMENT_S3_BUCKET'),
            // Non-AWS stores (MinIO, R2, B2, Spaces, GCS interop) set the
            // endpoint; most of them also need path-style addressing.
            'endpoint' => env('WAYFINDR_ATTACHMENT_S3_ENDPOINT'),
            'use_path_style_endpoint' => filter_var(env('WAYFINDR_ATTACHMENT_S3_USE_PATH_STYLE', false), FILTER_VALIDATE_BOOL),
            'root' => env('WAYFINDR_ATTACHMENT_S3_ROOT', 'attachments'),
            // Flysystem sends a canned ACL on every write; modern AWS buckets
            // (Object Ownership: bucket owner enforced, the default) reject any
            // ACL except bucket-owner-full-control — which is also accepted by
            // ACL-enabled buckets and keeps same-account objects private, so it
            // is the default that works everywhere on AWS. Stores that reject it
            // (some S3-compatibles) can override with e.g. private.
            'options' => array_filter([
                'ACL' => env('WAYFINDR_ATTACHMENT_S3_ACL', 'bucket-owner-full-control'),
            ]),
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Optional offsite backup destination (ADR 0010): any S3-compatible
        // object store, with its OWN credentials (separate from attachments).
        // A deliberately non-`attachments*` name so wayfindr:backup accepts it
        // and the orphaned-attachment sweep never touches it. Point
        // WAYFINDR_BACKUP_DISK=backups and set these to enable offsite backup.
        'backups' => [
            'driver' => 's3',
            'key' => env('WAYFINDR_BACKUP_S3_KEY'),
            'secret' => env('WAYFINDR_BACKUP_S3_SECRET'),
            'region' => env('WAYFINDR_BACKUP_S3_REGION', 'us-east-1'),
            'bucket' => env('WAYFINDR_BACKUP_S3_BUCKET'),
            'endpoint' => env('WAYFINDR_BACKUP_S3_ENDPOINT'),
            'use_path_style_endpoint' => filter_var(env('WAYFINDR_BACKUP_S3_USE_PATH_STYLE', false), FILTER_VALIDATE_BOOL),
            'root' => env('WAYFINDR_BACKUP_S3_ROOT', ''),
            'options' => array_filter([
                'ACL' => env('WAYFINDR_BACKUP_S3_ACL', 'bucket-owner-full-control'),
            ]),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];

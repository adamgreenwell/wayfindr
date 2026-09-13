<?php

declare(strict_types=1);

$compiled = env('VIEW_COMPILED_PATH');

return [
    'paths' => [resource_path('views')],

    // This config is copied into the image only. Direct execs inherit the
    // original container environment, including a blank override, rather than
    // the entrypoint's exports. Give both paths the same isolated cache.
    'compiled' => is_string($compiled) && $compiled !== ''
        ? $compiled
        : base_path('bootstrap/cache/views'),
];

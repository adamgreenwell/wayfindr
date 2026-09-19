<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Published from the framework default for one reason: `max_age` has no
    | `env()` behind it, so it cannot be changed without this file. Everything
    | else is kept as the framework ships it, deliberately.
    |
    | Wayfindr's widget runs on a customer's page and talks to the install
    | cross-origin, so these settings are load-bearing for every visitor.
    |
    */

    // The widget API and nothing else. (`sanctum/csrf-cookie` is dropped from
    // the framework list: Sanctum is not installed here.)
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // A site's public key is a public identifier, not a secret (SECURITY.md),
    // and the widget is embedded on domains the install does not know in
    // advance. Authorisation is the visitor session, never the origin.
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    // MUST stay '*'. The CORS layer answers a preflight by ECHOING the headers
    // the browser asked for; the Fetch spec's `*` wildcard does NOT cover
    // `Authorization`, so an explicit list that forgot it would break every
    // widget read with no error the operator could see.
    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // Ten minutes. The widget polls on a five-second cadence, so an uncached
    // preflight doubles those requests -- and with an active cobrowse session
    // flushing mutations every 50ms, the framework default of 0 costs an
    // install up to ~1,200 needless round trips a minute.
    //
    // The cost of caching is that a LATER tightening of this policy takes up to
    // ten minutes to reach a browser that already asked. That is acceptable
    // here because preflight is not doing security work on this API: it carries
    // no ambient authority (see `supports_credentials` below), so the browser
    // still validates the actual response's origin regardless.
    'max_age' => 600,

    // MUST stay false. With `allowed_origins` at '*', turning this on makes the
    // CORS layer reflect the caller's origin instead of returning '*' -- which
    // is reflect-any-origin-with-credentials. The widget API has no cookie or
    // session to send, so it needs nothing from this.
    'supports_credentials' => false,

];

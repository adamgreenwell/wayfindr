<?php

use App\Http\Controllers\Widget\WidgetScriptController;
use Illuminate\Support\Facades\Route;

/*
 * Public assets, deliberately registered OUTSIDE the `web` middleware group.
 *
 * Every visitor to every page of every install fetches these, before they have
 * interacted with anything at all. Inside the `web` group that cost three
 * things (issue #955), all measured on a real deployment:
 *
 *  - a `sessions` row written per script load, per visitor, per page view,
 *    against the operator's own database;
 *  - `wayfindr-session` and `XSRF-TOKEN` set on visitors who never opened the
 *    widget -- a consent surface in a product whose presence collection is
 *    opt-in with an explicit decline path (ADR 0019);
 *  - a `Set-Cookie` header on the same response as `Cache-Control`, which makes
 *    shared caches decline to store it, so the cache lifetime never applied.
 *
 * Nothing registered here may read the session, the authenticated user, or the
 * CSRF token. If a public asset ever needs one of those, it is not a public
 * asset and does not belong in this file.
 *
 * Global middleware still applies, which is intended: a release that is not fit
 * to serve is not fit to serve these either (ADR 0013).
 */

Route::get('/widget.js', WidgetScriptController::class)->name('widget.script');

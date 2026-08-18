<?php

namespace App\Support;

use App\Models\Site;

/**
 * The one place a public entry point turns a site public key into a site.
 *
 * Eleven widget-facing entry points used to run this query themselves, which
 * made "is this site still in service?" a question eleven callers had to answer
 * the same way. They will not stay in agreement: archiving a site has to stop
 * every one of them at once, and the failure mode of missing one is silent -
 * the site looks retired in the dashboard while a forgotten endpoint keeps
 * serving visitors.
 *
 * An archived site is indistinguishable from an unknown one out here. That is
 * deliberate: the public surface should not confirm that a key it refuses to
 * serve nonetheless exists.
 */
class WidgetSiteResolver
{
    /**
     * Resolve a servable site, or abort 404.
     */
    public static function resolveOrFail(?string $publicKey): Site
    {
        $site = self::find($publicKey);

        abort_unless($site, 404, 'Site not found.');

        return $site;
    }

    /**
     * Resolve a servable site, or null when there is no such site or it has
     * been archived.
     */
    public static function find(?string $publicKey): ?Site
    {
        if ($publicKey === null || $publicKey === '') {
            return null;
        }

        return Site::query()
            ->servable()
            ->where('public_key', $publicKey)
            ->first();
    }
}

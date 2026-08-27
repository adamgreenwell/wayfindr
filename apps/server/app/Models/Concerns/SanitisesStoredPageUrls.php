<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Visitors\VisitorPageUrl;
use Illuminate\Database\Eloquent\Model;

/**
 * Sanitise stored page addresses on the way to the database, whoever is writing.
 *
 * The sanitiser at each entry point stops NEW query strings arriving. It cannot
 * stop an OLD one coming back: a request that read a row before the sweep
 * cleaned it holds the tokenised value, and any writer saving the whole
 * metadata document afterwards restores it. The typing endpoints do exactly
 * that -- read the document, set a flag, save it all back -- and there are half
 * a dozen more shaped the same way.
 *
 * Patching each writer is the version that works until somebody adds the
 * seventh. A `saving` hook is the version where the value cannot be wrong in
 * the database regardless of what was read, when, or by whom: every write
 * converges, and ordering stops being something anyone has to reason about.
 */
trait SanitisesStoredPageUrls
{
    /**
     * Dot paths within `metadata` holding a page address.
     *
     * @return array<int, string>
     */
    abstract protected static function pageUrlPaths(): array;

    protected static function bootSanitisesStoredPageUrls(): void
    {
        static::saving(function (Model $model): void {
            $metadata = $model->getAttribute('metadata');

            if (! is_array($metadata)) {
                return;
            }

            $changed = false;

            foreach (static::pageUrlPaths() as $path) {
                if (self::sanitisePageUrlPath($metadata, explode('.', $path))) {
                    $changed = true;
                }
            }

            if ($changed) {
                $model->setAttribute('metadata', $metadata);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $path
     */
    private static function sanitisePageUrlPath(array &$metadata, array $path): bool
    {
        $key = array_shift($path);

        if (! array_key_exists($key, $metadata)) {
            return false;
        }

        if ($path !== []) {
            if (! is_array($metadata[$key])) {
                return false;
            }

            return self::sanitisePageUrlPath($metadata[$key], $path);
        }

        $stored = $metadata[$key];

        if (! is_string($stored) || $stored === '') {
            return false;
        }

        $sanitised = VisitorPageUrl::sanitise($stored);

        if ($sanitised === $stored) {
            return false;
        }

        $metadata[$key] = $sanitised;

        return true;
    }
}

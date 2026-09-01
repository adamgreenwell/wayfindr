<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The queue-pressure sentence, composed rather than translated.
 *
 * `CobrowseTransportPressure::format()` builds the English by gluing parts with
 * `', '` and an English pluraliser, and that structure is the half that does not
 * travel: another language decides its own plural rule and its own list
 * separator. So the COUNTS cross the boundary and each surface composes.
 *
 * It lives here rather than inside `x-cobrowse-pressure` because two surfaces
 * need it and only one of them can use a component. The conversation detail
 * page renders the sentence on its own, where a component is natural; the queue
 * row interpolates it into `conversations.row.pressure`, which takes a string.
 * Leaving the logic in the component meant the queue kept using the English
 * value and marking it as English -- honest, and still English on a German page.
 */
final class CobrowsePressureSentence
{
    /**
     * @param  array<string, mixed>|null  $counts
     */
    public static function for(?array $counts): string
    {
        $counts ??= [];
        $parts = [];

        $dropped = (int) ($counts['dropped_batches'] ?? 0);
        $skipped = (int) ($counts['skipped_mutations'] ?? 0);

        if ($dropped > 0) {
            $parts[] = trans_choice('cobrowse.pressure.dropped', $dropped, ['count' => ReaderNumber::count($dropped)]);
        }

        if ($skipped > 0) {
            $parts[] = trans_choice('cobrowse.pressure.skipped', $skipped, ['count' => ReaderNumber::count($skipped)]);
        }

        if ($parts !== []) {
            return implode(__('cobrowse.pressure.separator'), $parts);
        }

        return __(($counts['has_recent_report'] ?? false)
            ? 'cobrowse.pressure.none_recent'
            : 'cobrowse.pressure.none');
    }
}

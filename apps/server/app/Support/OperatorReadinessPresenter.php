<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Throwable;

/**
 * Turn request-neutral readiness state into copy for an extracted surface.
 *
 * OperatorReadiness remains usable by commands, tests, and pages that have not
 * crossed the translation boundary yet. A check opts into this presenter with
 * a stable variant and language-neutral parameters; the surface decides when
 * those semantics become reader-facing prose.
 */
final class OperatorReadinessPresenter
{
    /**
     * @param  array<string, mixed>  $check
     * @return array<string, mixed>
     */
    public static function localize(array $check): array
    {
        $translation = $check['translation'] ?? null;

        if (! is_array($translation) || ! is_string($translation['variant'] ?? null)) {
            return $check;
        }

        $key = (string) ($check['key'] ?? '');
        $base = "operator.readiness.checks.{$key}";
        $label = __($base.'.label');
        $confirmation = is_array($check['confirmation'] ?? null) ? $check['confirmation'] : null;

        if ($confirmation !== null) {
            return [
                ...$check,
                'action' => self::feedback('operator.readiness.confirmation.action'),
                'detail' => self::confirmationDetail($confirmation),
                'label' => $label,
                'status_label' => self::statusLabel($check),
                'summary' => self::feedback(
                    ($confirmation['freshness_status'] ?? null) === 'stale'
                        ? 'operator.readiness.confirmation.summary_stale'
                        : 'operator.readiness.confirmation.summary_fresh',
                    localized: ['label' => $label],
                ),
            ];
        }

        $variant = $translation['variant'];
        $parameters = is_array($translation['parameters'] ?? null) ? $translation['parameters'] : [];
        $localized = [];

        if (array_key_exists('count', $parameters)) {
            $localized['count'] = ReaderNumber::count((int) $parameters['count']);
            unset($parameters['count']);
        }

        if (is_array($translation['states'] ?? null) && $translation['states'] !== []) {
            $localized['state_summary'] = self::transportStateSummary($translation['states']);
        }

        return [
            ...$check,
            'action' => self::feedback("{$base}.{$variant}.action", $parameters, $localized),
            'detail' => self::feedback("{$base}.{$variant}.detail", $parameters, $localized),
            'label' => $label,
            'status_label' => self::statusLabel($check),
            'summary' => self::feedback("{$base}.{$variant}.summary", $parameters, $localized),
        ];
    }

    /** @param array<string, mixed> $states */
    private static function transportStateSummary(array $states): string
    {
        $parts = [];

        foreach (['live', 'degraded', 'reconnecting', 'stale', 'unavailable'] as $state) {
            $count = (int) ($states[$state] ?? 0);

            if ($count > 0) {
                $parts[] = trans_choice(
                    "operator.readiness.transport_states.{$state}",
                    $count,
                    ['count' => ReaderNumber::count($count)],
                );
            }
        }

        return $parts === []
            ? __('operator.readiness.transport_states.none')
            : implode(', ', $parts);
    }

    /** @param array<string, mixed> $check */
    private static function statusLabel(array $check): string
    {
        if (($check['confirmation']['freshness_status'] ?? null) === 'stale') {
            return __('operator.readiness.status.due_again');
        }

        if (($check['key'] ?? null) === 'cobrowse_transport'
            && ($check['translation']['variant'] ?? null) === 'no_samples') {
            return __('operator.readiness.status.no_data');
        }

        if (($check['key'] ?? null) === 'web_push'
            && ($check['translation']['variant'] ?? null) === 'unset') {
            return __('operator.readiness.status.optional');
        }

        return match ($check['status'] ?? null) {
            'ready' => __('operator.readiness.status.ready'),
            'manual' => __('operator.readiness.status.confirm_this'),
            default => ($check['key'] ?? null) === 'language_and_region'
                ? __('operator.readiness.status.confirm_this')
                : __('operator.readiness.status.needs_attention'),
        };
    }

    /**
     * @param  array<string, mixed>  $confirmation
     * @return array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}
     */
    private static function confirmationDetail(array $confirmation): array
    {
        $age = self::relativeAge($confirmation['confirmed_at'] ?? null);
        $hasNote = (bool) ($confirmation['note_present'] ?? false);
        $knownOperator = (bool) ($confirmation['confirmed_by_known'] ?? true);
        $suffix = ($age !== null ? '_with_age' : '').($hasNote ? '_with_note' : '');
        $neutral = [];
        $localized = [];

        if ($knownOperator) {
            $neutral['name'] = (string) ($confirmation['confirmed_by'] ?? '');
        } else {
            $localized['name'] = __('operator.readiness.confirmation.unknown_operator');
        }

        if ($age !== null) {
            $localized['age'] = $age;
        }

        return self::feedback(
            'operator.readiness.confirmation.detail'.$suffix,
            $neutral,
            $localized,
        );
    }

    private static function relativeAge(mixed $confirmedAt): ?string
    {
        if (! is_string($confirmedAt) || trim($confirmedAt) === '') {
            return null;
        }

        try {
            $confirmed = CarbonImmutable::parse($confirmedAt);
            $days = (int) $confirmed->diffInDays(now());

            if ($days > 0 && $days < 30) {
                return trans_choice(
                    'operator.readiness.confirmation.days_ago',
                    $days,
                    ['count' => ReaderNumber::count($days)],
                );
            }

            return $confirmed->locale(App::currentLocale())->diffForHumans();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $localized
     * @return array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}
     */
    private static function feedback(string $key, array $parameters = [], array $localized = []): array
    {
        return [
            'key' => $key,
            'localized_parameters' => array_map(static fn (mixed $value): string => (string) $value, $localized),
            'parameters' => array_map(static fn (mixed $value): string => (string) $value, $parameters),
        ];
    }
}

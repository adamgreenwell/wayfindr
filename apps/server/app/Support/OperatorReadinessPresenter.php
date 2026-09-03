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

        return [
            ...$check,
            'action' => self::feedback("{$base}.{$variant}.action", $parameters),
            'detail' => self::feedback("{$base}.{$variant}.detail", $parameters),
            'label' => $label,
            'status_label' => self::statusLabel($check),
            'summary' => self::feedback("{$base}.{$variant}.summary", $parameters),
        ];
    }

    /** @param array<string, mixed> $check */
    private static function statusLabel(array $check): string
    {
        if (($check['confirmation']['freshness_status'] ?? null) === 'stale') {
            return __('operator.readiness.status.due_again');
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
            return CarbonImmutable::parse($confirmedAt)
                ->locale(App::currentLocale())
                ->diffForHumans();
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

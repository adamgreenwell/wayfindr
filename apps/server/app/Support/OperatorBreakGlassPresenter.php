<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BreakGlassGrant;
use App\Models\User;
use App\Models\Visitor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * Translate the operator-facing break-glass flow without translating account
 * content. Grants retain stable English labels for audit trails and other
 * request-neutral consumers; this presenter turns their semantic states into
 * the current operator's catalogue at the HTTP boundary.
 */
final class OperatorBreakGlassPresenter
{
    /**
     * @return array{
     *     grant: BreakGlassGrant,
     *     scope: array{label: string, value: string|null},
     *     status: array{label: string, language: string|null},
     *     requested_at: string,
     *     expires_at: string|null
     * }
     */
    public static function grant(BreakGlassGrant $grant): array
    {
        return [
            'grant' => $grant,
            'scope' => self::scope($grant->scope_type, $grant->scopeLabel()),
            'status' => self::grantStatus($grant),
            'requested_at' => $grant->created_at->diffForHumans(),
            'expires_at' => $grant->expires_at?->diffForHumans(),
        ];
    }

    /** @return array{label: string, value: string|null} */
    public static function scope(string $type, string $storedLabel): array
    {
        if ($type === BreakGlassGrant::SCOPE_ACCOUNT) {
            return ['label' => __('operator_access.scopes.account'), 'value' => null];
        }

        [$sourcePrefix, $key] = match ($type) {
            BreakGlassGrant::SCOPE_CONVERSATION => ['Conversation', 'conversation'],
            BreakGlassGrant::SCOPE_SITE => ['Site', 'site'],
            default => [null, null],
        };

        if ($sourcePrefix === null || $key === null) {
            return [
                'label' => __('operator_access.scopes.other'),
                'value' => $storedLabel !== '' ? $storedLabel : $type,
            ];
        }

        $value = str_starts_with($storedLabel, $sourcePrefix.' ')
            ? substr($storedLabel, strlen($sourcePrefix) + 1)
            : $storedLabel;

        if ($value === '(deleted)' || $value === '(out of scope)') {
            return [
                'label' => __('operator_access.scopes.'.$key.'_'.($value === '(deleted)' ? 'deleted' : 'out_of_scope')),
                'value' => null,
            ];
        }

        return [
            'label' => __('operator_access.scopes.'.$key),
            'value' => $value !== '' ? $value : null,
        ];
    }

    /** @return array{label: string, language: string|null} */
    public static function grantStatus(BreakGlassGrant $grant): array
    {
        $status = $grant->status === BreakGlassGrant::STATUS_ACTIVE && ! $grant->isActive()
            ? BreakGlassGrant::STATUS_EXPIRED
            : $grant->status;

        $key = match ($status) {
            BreakGlassGrant::STATUS_REQUESTED => 'awaiting_approval',
            BreakGlassGrant::STATUS_ACTIVE => 'active',
            BreakGlassGrant::STATUS_DENIED => 'denied',
            BreakGlassGrant::STATUS_CLOSED => 'closed_early',
            BreakGlassGrant::STATUS_EXPIRED => 'expired',
            default => null,
        };

        return $key === null
            ? ['label' => $status, 'language' => '']
            : ['label' => __('operator_access.statuses.'.$key), 'language' => null];
    }

    /** @return array{label: string, language: string|null} */
    public static function ticketValue(string $kind, ?string $value): array
    {
        if ($value === null || $value === '') {
            return ['label' => __('operator_break_glass.values.not_set'), 'language' => null];
        }

        $known = match ($kind) {
            'status' => ['open', 'pending', 'closed'],
            'priority' => ['low', 'normal', 'high', 'urgent'],
            'category' => ['question', 'bug', 'billing', 'access', 'task', 'other'],
            default => [],
        };

        $catalogue = match ($kind) {
            'status' => 'statuses',
            'priority' => 'priorities',
            'category' => 'categories',
            default => null,
        };

        return $catalogue !== null && in_array($value, $known, true)
            ? ['label' => __("tickets.{$catalogue}.{$value}"), 'language' => null]
            : ['label' => $value, 'language' => ''];
    }

    /** @return array{key: string, name: string|null} */
    public static function sender(?object $sender): array
    {
        return match (true) {
            $sender instanceof Visitor => ['key' => 'visitor', 'name' => null],
            $sender instanceof User => ['key' => 'agent', 'name' => $sender->name],
            default => ['key' => 'system', 'name' => null],
        };
    }

    /** @return array<int, string> */
    public static function durationChoices(): array
    {
        return [
            15 => __('operator_break_glass.request.duration.options.fifteen_minutes'),
            BreakGlassGrant::DEFAULT_MINUTES => __('operator_break_glass.request.duration.options.one_hour'),
            240 => __('operator_break_glass.request.duration.options.four_hours'),
            BreakGlassGrant::MAX_MINUTES => __('operator_break_glass.request.duration.options.one_day_maximum'),
        ];
    }

    /**
     * The write request stores only a key and raw semantic context. The GET
     * translates it using the language and clock of the page that renders it.
     *
     * @return array{key: string, scope: array{label: string, value: string|null}|null, until: string|null}|null
     */
    public static function flash(Request $request): ?array
    {
        $key = $request->session()->get('status');

        if (in_array($key, [
            'operator_break_glass.flash.already_expired',
            'operator_break_glass.flash.closed',
        ], true)) {
            return ['key' => $key, 'scope' => null, 'until' => null];
        }

        if (! in_array($key, [
            'operator_break_glass.flash.requested',
            'operator_break_glass.flash.self_approved',
        ], true)) {
            return null;
        }

        $context = $request->session()->get('operator_break_glass_status');
        $scopeType = is_array($context) ? ($context['scope_type'] ?? null) : null;
        $scopeLabel = is_array($context) ? ($context['scope_label'] ?? null) : null;

        if (! is_string($scopeType) || ! is_string($scopeLabel)) {
            return [
                'key' => $key === 'operator_break_glass.flash.requested'
                    ? 'operator_break_glass.flash.requested_generic'
                    : 'operator_break_glass.flash.self_approved_generic',
                'scope' => null,
                'until' => null,
            ];
        }

        $until = null;

        if ($key === 'operator_break_glass.flash.self_approved') {
            $expiresAt = is_array($context) ? ($context['expires_at'] ?? null) : null;

            if (! is_string($expiresAt)) {
                return ['key' => 'operator_break_glass.flash.self_approved_generic', 'scope' => null, 'until' => null];
            }

            try {
                $until = ReaderClock::timeWithZone(CarbonImmutable::parse($expiresAt));
            } catch (Throwable) {
                return ['key' => 'operator_break_glass.flash.self_approved_generic', 'scope' => null, 'until' => null];
            }
        }

        return [
            'key' => $key,
            'scope' => self::scope($scopeType, $scopeLabel),
            'until' => $until,
        ];
    }
}

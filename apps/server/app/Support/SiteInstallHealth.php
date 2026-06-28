<?php

namespace App\Support;

use App\Models\Visitor;
use Illuminate\Support\Carbon;

class SiteInstallHealth
{
    /**
     * @return array{label: string, tone: string, detail: string, needs_attention: bool, action_label: string|null}
     */
    public static function fromVisitor(?Visitor $visitor, ?Carbon $now = null): array
    {
        $lastSeenAt = $visitor?->last_seen_at;

        if (! $lastSeenAt) {
            return [
                'label' => 'Not installed',
                'tone' => 'attention',
                'detail' => 'No check-in yet',
                'needs_attention' => true,
                'action_label' => 'Finish install',
            ];
        }

        $now ??= now();

        if ($lastSeenAt->greaterThanOrEqualTo($now->copy()->subMinutes(30))) {
            return [
                'label' => 'Live',
                'tone' => 'ready',
                'detail' => 'Seen '.$lastSeenAt->diffForHumans(),
                'needs_attention' => false,
                'action_label' => null,
            ];
        }

        return [
            'label' => 'Needs check',
            'tone' => 'manual',
            'detail' => 'Seen '.$lastSeenAt->diffForHumans(),
            'needs_attention' => true,
            'action_label' => 'Review install',
        ];
    }

    /**
     * Compare the host the widget last checked in from against the site's
     * configured domain, so operators can confirm the widget is installed on
     * the intended host and not, for example, a stale public key pasted onto a
     * different site. This is additive and intentionally separate from
     * fromVisitor(), which stays recency-only so global attention rollups do not
     * gain host false positives.
     *
     * @return array{checked_in_host: string|null, status: string, label: string, tone: string, detail: string, needs_attention: bool}
     */
    public static function hostDiagnostic(?Visitor $visitor, ?string $expectedDomain): array
    {
        $checkedInHost = self::hostFromUrl(
            is_array($visitor?->metadata) ? ($visitor->metadata['last_page_url'] ?? null) : null
        );
        $expectedHost = self::normalizeHost($expectedDomain);

        if ($checkedInHost === null) {
            return [
                'checked_in_host' => null,
                'status' => 'unknown',
                'label' => 'Host unknown',
                'tone' => 'manual',
                'detail' => 'No widget host check-in recorded yet.',
                'needs_attention' => false,
            ];
        }

        if ($expectedHost === null) {
            return [
                'checked_in_host' => $checkedInHost,
                'status' => 'unverified',
                'label' => 'Host unverified',
                'tone' => 'manual',
                'detail' => "Checking in from {$checkedInHost}. Set this site's domain to verify the widget host.",
                'needs_attention' => false,
            ];
        }

        if (self::hostMatches($checkedInHost, $expectedHost)) {
            return [
                'checked_in_host' => $checkedInHost,
                'status' => 'match',
                'label' => 'Host verified',
                'tone' => 'ready',
                'detail' => "Checking in from {$checkedInHost}.",
                'needs_attention' => false,
            ];
        }

        return [
            'checked_in_host' => $checkedInHost,
            'status' => 'mismatch',
            'label' => 'Host mismatch',
            'tone' => 'attention',
            'detail' => "Checking in from {$checkedInHost}, but this site is configured for {$expectedHost}. Confirm the site key is installed on the intended host.",
            'needs_attention' => true,
        ];
    }

    private static function hostFromUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? mb_strtolower($host) : null;
    }

    private static function normalizeHost(?string $domain): ?string
    {
        if (! is_string($domain) || trim($domain) === '') {
            return null;
        }

        // Site domains are stored as host[:port]; compare host only.
        $host = explode(':', trim($domain))[0];

        return mb_strtolower($host);
    }

    private static function hostMatches(string $candidate, string $expected): bool
    {
        return $candidate === $expected || str_ends_with($candidate, '.'.$expected);
    }
}

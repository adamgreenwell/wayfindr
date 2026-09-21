<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Models\Site;
use App\Models\Visitor;
use App\Support\VisitorContextSanitizer;

/**
 * Decide what, if anything, to write to `visitors.external_id` for a request.
 *
 * One class rather than the byte-identical private method that used to sit in
 * both `BootstrapController` and `ConversationController`. That duplication was
 * a hazard for exactly this change: patching one copy would have left bootstrap
 * accepting an identifier the very next request rejected.
 */
final class ExternalIdentifierUpdate
{
    public function __construct(
        private readonly VisitorContextSanitizer $sanitizer,
        private readonly VisitorIdentityVerification $verification,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{external_id?: string}
     */
    public function for(Site $site, Visitor $visitor, array $validated): array
    {
        if (! array_key_exists('external_id', $validated)) {
            return [];
        }

        $externalId = $this->sanitizer->sanitizeIdentifier($validated['external_id']);

        if ($externalId === null) {
            return [];
        }

        // Verification first, and before the exclusivity read below, so a
        // caller who cannot prove the host vouched for this identifier never
        // causes a lookup of who holds it.
        //
        // Be precise about what this ordering does and does not buy, because
        // it is easy to credit it with more than it earns: it does NOT close
        // the enumeration oracle -- the verification gate does that, by making
        // the answer `false` for an unverified caller whatever the table says.
        // Reversing these two lines changes no response, and a mutation that
        // reverses them passes every test asserting behaviour. What the order
        // removes is the timing side channel left over once the response is
        // constant: a read that runs, and whose duration depends on whether a
        // row was found, on behalf of somebody who has proved nothing. The
        // test below pins it by counting queries rather than by reading
        // responses, since responses cannot see it.
        if ($this->verification->isRequiredFor($site)
            && ! $this->verification->verifies($site, $externalId, $validated['identity_hash'] ?? null)) {
            return [];
        }

        // First come, first served, unchanged. Note what this does NOT do even
        // when the claim verifies: a second browser presenting a verified id
        // another visitor already holds is still left without it, because
        // Wayfindr resolves a visitor by `anonymous_id` alone and
        // `(site_id, external_id)` is unique. Verification proves WHO is
        // claiming; it does not join two browsers into one visitor, and the
        // widget README says so.
        $belongsToAnotherVisitor = Visitor::query()
            ->where('site_id', $site->id)
            ->where('external_id', $externalId)
            ->when($visitor->exists, fn ($query) => $query->where('id', '!=', $visitor->getKey()))
            ->exists();

        return $belongsToAnotherVisitor ? [] : ['external_id' => $externalId];
    }
}

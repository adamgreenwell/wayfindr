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

        // The value as the host sent it, which is the value they HASHED. Keep
        // it separate from the sanitised one below, because they are not the
        // same string: `safeValue()` trims and truncates to 160 characters
        // while validation admits 255, so any identifier in that band -- or
        // with a stray newline from a template -- is stored shortened.
        // Verifying the shortened form would fail against a hash the host
        // computed correctly, and fail SILENTLY: the visitor would simply never
        // be identified, with nothing anywhere saying why.
        $presentedId = is_string($validated['external_id']) ? $validated['external_id'] : null;

        $externalId = $this->sanitizer->sanitizeIdentifier($validated['external_id']);

        if ($externalId === null || $presentedId === null) {
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
        // Verified against what the host signed, not against what we are about
        // to store. The README tells them to hash the value they pass, and that
        // is the only contract they can implement: replicating this sanitiser
        // on their side would be absurd, and getting it subtly wrong would look
        // exactly like a working integration that identifies nobody.
        if ($this->verification->isRequiredFor($site)
            && ! $this->verification->verifies($site, $presentedId, $validated['identity_hash'] ?? null)) {
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

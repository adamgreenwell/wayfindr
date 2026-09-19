<?php

namespace App\Support\Attachments;

use App\Models\Conversation;
use App\Models\ConversationMessageAttachment;
use App\Models\Site;
use App\Models\Visitor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Short-lived, signed links for a visitor's attachment downloads.
 *
 * An attachment URL is the one widget request that cannot carry a credential in
 * a header: the file link opens in a new tab and the image preview is fetched
 * by `<img src>`, neither of which the widget controls. Until now that URL
 * carried the visitor's session credentials in its query string, which put them
 * in the access log, in browser history -- and, because the widget writes the
 * URL into `href` and `src`, in the DOM of the customer's own page, where any
 * third-party script on that page could read them.
 *
 * A signed link replaces a session credential with a capability for ONE
 * attachment, for a bounded time. Be clear about what that does and does not
 * buy: for a new-tab navigation or an `<img>` fetch the server cannot identify
 * the caller at all -- there is no header to read and no first-party cookie to
 * rely on -- so this is a bearer capability and cannot be made otherwise.
 * What changes is the blast radius of holding one.
 */
class AttachmentDownloadLink
{
    /**
     * Mint the URL the widget renders for an attachment.
     *
     * The expiry is QUANTISED to a window rather than measured from now, and
     * that is load-bearing rather than tidiness. The widget skips re-rendering
     * a transcript whose message signature is unchanged; a URL that differed on
     * every poll would either change that signature -- re-rendering the whole
     * transcript every few seconds and re-fetching every loaded image -- or be
     * left out of it, in which case the `href` already in the DOM would go
     * stale without anything noticing. Quantising makes every mint inside a
     * window byte-identical, so the signature is stable and the link rotates
     * once per window instead.
     */
    public static function for(Conversation $conversation, ConversationMessageAttachment $attachment): ?string
    {
        $site = $conversation->site;
        $visitor = $conversation->visitor;

        if ($site === null || $visitor === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            'widget.conversations.attachments.download',
            self::expiresAt(),
            [
                'supportCode' => $conversation->support_code,
                'attachment' => $attachment->id,
                // Public by policy, and kept in the URL deliberately: the
                // widget-attachment throttle keys on it, and without it every
                // tenant's visitors behind one NAT would share a bucket.
                'site_public_key' => $site->public_key,
                'v' => self::visitorBinding($site, $visitor),
            ],
            // RELATIVE, not absolute. An absolute signature is computed over the
            // scheme and host, and `temporarySignedRoute` takes those from
            // APP_URL while validation recomputes them from the request. On any
            // install behind a TLS-terminating proxy that did not set
            // TRUSTED_PROXIES -- which `bootstrap/app.php` says is most of them
            // -- those disagree and every download 403s. It would pass every
            // local and CI test and fail only in production.
            absolute: false,
        );
    }

    /**
     * Ties a link to the visitor it was minted for.
     *
     * The signature already proves the URL was minted here and not altered.
     * This proves WHICH visitor it was minted for, so an identity merge that
     * moves the conversation to another row retires every link already handed
     * out. It is not a proof of who is clicking, and nothing should read it as
     * one.
     *
     * Keyed with the app key, following `ProactiveVisitorKey`, so it cannot be
     * recomputed from values that appear beside it in the URL. Deliberately NOT
     * `VisitorSessionToken::sessionIdentity()`: that is the refresh limiter's
     * quota key, and one string serving as both a quota key and an
     * authorisation term is how a change to either silently moves the other.
     */
    public static function visitorBinding(Site $site, Visitor $visitor): string
    {
        return hash_hmac(
            'sha256',
            'wayfindr-attachment-link|'.$site->id.'|'.$visitor->id,
            (string) config('app.key'),
        );
    }

    private static function expiresAt(): Carbon
    {
        $ttl = max(1, (int) config('wayfindr.attachments.link_ttl_minutes', 60));

        // Half the lifetime, so a link is always good for at least $ttl/2 and
        // at most $ttl, and rotates once per window.
        $window = max(1, (int) floor($ttl / 2));
        $seconds = $window * 60;

        return Carbon::createFromTimestamp(
            (int) (ceil((now()->getTimestamp() + ($ttl * 60)) / $seconds) * $seconds)
        );
    }
}

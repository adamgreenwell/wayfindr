<script data-agent-push-guest-cleanup>
    (function () {
        // A root-scoped push subscription outlives the Laravel session. Shed it
        // on every unauthenticated app surface so logging out, expiring a
        // session, or pausing for two-factor cannot leave the prior agent's
        // locked-screen alerts attached to a shared browser.
        if (! window.isSecureContext
            || ! ('serviceWorker' in navigator)
            || ! ('PushManager' in window)) {
            return;
        }

        function unsubscribe(subscription, attemptsRemaining) {
            return subscription.unsubscribe()
                .then(function (unsubscribed) {
                    if (unsubscribed === false) {
                        throw new Error('The logged-out browser subscription remains active.');
                    }
                })
                .catch(function (failure) {
                    if (attemptsRemaining <= 1) {
                        throw failure;
                    }

                    return unsubscribe(subscription, attemptsRemaining - 1);
                });
        }

        navigator.serviceWorker.getRegistration('/wayfindr-sw.js')
            .then(function (registration) {
                return registration ? registration.pushManager.getSubscription() : null;
            })
            .then(function (subscription) {
                return subscription ? unsubscribe(subscription, 2) : null;
            })
            .catch(function () {
                // Push cleanup is best effort on an unauthenticated surface;
                // it must never make login or account recovery unavailable.
            });
    })();
</script>

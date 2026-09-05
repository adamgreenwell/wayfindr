@props(['statusEndpoint'])

<script data-agent-push-ownership-guard>
    (function () {
        // The profile owns the complete opt-in lifecycle and performs the same
        // check with user-facing status. Every other authenticated dashboard
        // page only needs the account-transition privacy guard below.
        if (document.querySelector('[data-agent-push-subscription]')
            || ! window.isSecureContext
            || ! ('serviceWorker' in navigator)
            || ! ('PushManager' in window)) {
            return;
        }

        var optInLockPrefix = 'wayfindr:push-opt-in:';

        function unsubscribeUnowned(subscription, attemptsRemaining) {
            return subscription.unsubscribe()
                .then(function (unsubscribed) {
                    if (unsubscribed === false) {
                        throw new Error('The unowned browser subscription remains active.');
                    }
                })
                .catch(function (failure) {
                    if (attemptsRemaining <= 1) {
                        throw failure;
                    }

                    return unsubscribeUnowned(subscription, attemptsRemaining - 1);
                });
        }

        function subscriptionStatus(endpoint, attemptsRemaining) {
            var csrf = document.querySelector('meta[name="csrf-token"]');

            return fetch(@json($statusEndpoint), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : '',
                },
                body: JSON.stringify({ endpoint: endpoint }),
            }).then(function (response) {
                if (! response.ok) {
                    throw new Error('Push subscription ownership could not be checked.');
                }

                return response.json();
            }).then(function (payload) {
                if (! payload || ! ['owned', 'foreign', 'missing'].includes(payload.status)) {
                    throw new Error('Push subscription ownership could not be checked.');
                }

                return payload;
            }).catch(function (failure) {
                if (attemptsRemaining <= 1) {
                    throw failure;
                }

                return subscriptionStatus(endpoint, attemptsRemaining - 1);
            });
        }

        navigator.serviceWorker.getRegistration('/wayfindr-sw.js')
            .then(function (registration) {
                return registration ? registration.pushManager.getSubscription() : null;
            })
            .then(function (subscription) {
                if (! subscription) {
                    return;
                }

                var verifyOwnership = function () {
                    return subscriptionStatus(subscription.endpoint, 2)
                        .then(function (payload) {
                            if (payload.status === 'owned') {
                                return;
                            }

                            // Do not delete or reassign a server row; only stop
                            // this shared browser receiving from an endpoint the
                            // current agent does not own.
                            return unsubscribeUnowned(subscription, 2);
                        })
                        .catch(function () {
                            // The lock is still held here. Privacy wins over
                            // availability when ownership cannot be proven.
                            return unsubscribeUnowned(subscription, 2).catch(function () {});
                        });
                };
                var verification = 'locks' in navigator
                    ? navigator.locks.request(
                        optInLockPrefix + subscription.endpoint,
                        { mode: 'exclusive' },
                        verifyOwnership
                    )
                    : verifyOwnership();

                return verification;
            })
            .catch(function () {
                // Push is optional. A failed ownership check must not block
                // the dashboard; the profile provides explicit recovery UI.
            });
    })();
</script>

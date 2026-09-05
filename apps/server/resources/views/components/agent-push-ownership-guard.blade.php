@props(['statusEndpoint'])

<script data-agent-push-ownership-guard>
    (function () {
        // The profile owns the complete opt-in lifecycle and performs the same
        // check with user-facing status. Every other authenticated dashboard
        // page only needs the account-transition privacy guard below.
        if (document.querySelector('[data-agent-push-preferences]')
            || ! window.isSecureContext
            || ! ('serviceWorker' in navigator)
            || ! ('PushManager' in window)) {
            return;
        }

        function unsubscribeForeign(subscription, attemptsRemaining) {
            return subscription.unsubscribe()
                .then(function (unsubscribed) {
                    if (unsubscribed === false) {
                        throw new Error('The foreign browser subscription remains active.');
                    }
                })
                .catch(function (failure) {
                    if (attemptsRemaining <= 1) {
                        throw failure;
                    }

                    return unsubscribeForeign(subscription, attemptsRemaining - 1);
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

                return subscriptionStatus(subscription.endpoint, 2)
                    .then(function (payload) {
                        if (payload.status !== 'foreign') {
                            return;
                        }

                        // Do not delete or reassign the prior agent's server
                        // row. Only stop this shared browser from receiving it.
                        return unsubscribeForeign(subscription, 2);
                    })
                    .catch(function () {
                        // Ownership is unknown after a bounded retry. Privacy
                        // wins over availability on a shared browser: remove
                        // the local subscription rather than risk continuing
                        // to receive a prior agent's locked-screen alerts.
                        return unsubscribeForeign(subscription, 2).catch(function () {});
                    });
            })
            .catch(function () {
                // Push is optional. A failed ownership check must not block
                // the dashboard; the profile provides explicit recovery UI.
            });
    })();
</script>

@props(['config'])

<script data-agent-push-subscription>
    (function () {
        var config = @json($config);
        var form = document.querySelector('[data-agent-push-preferences]');
        var checkbox = document.getElementById('push_alerts');
        var error = document.querySelector('[data-agent-push-error]');

        if (! form || ! checkbox) {
            return;
        }

        function showError(message) {
            if (! error) {
                return;
            }

            error.textContent = message || config.failedMessage;
            error.hidden = false;
        }

        function preserveAndDisable(message) {
            var preserved = document.createElement('input');

            preserved.type = 'hidden';
            preserved.name = 'push_alerts';
            preserved.value = checkbox.checked ? '1' : '0';
            form.appendChild(preserved);
            checkbox.disabled = true;
            showError(message || config.unsupportedMessage);
        }

        function pendingRemoval(endpoint) {
            var existing = form.querySelector('input[name="push_subscription_endpoint"]');

            if (! endpoint) {
                if (existing) {
                    existing.remove();
                }

                return;
            }

            var input = existing || document.createElement('input');

            input.type = 'hidden';
            input.name = 'push_subscription_endpoint';
            input.value = endpoint;

            if (! existing) {
                form.appendChild(input);
            }
        }

        if (! window.isSecureContext
            || ! ('serviceWorker' in navigator)
            || ! ('PushManager' in window)
            || ! ('Notification' in window)) {
            preserveAndDisable();

            return;
        }

        function applicationServerKey(value) {
            var padding = '='.repeat((4 - value.length % 4) % 4);
            var base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
            var raw = window.atob(base64);

            return Uint8Array.from(raw, function (character) {
                return character.charCodeAt(0);
            });
        }

        function base64Url(buffer) {
            var bytes = new Uint8Array(buffer);
            var binary = '';

            bytes.forEach(function (byte) {
                binary += String.fromCharCode(byte);
            });

            return window.btoa(binary)
                .replace(/\+/g, '-')
                .replace(/\//g, '_')
                .replace(/=+$/, '');
        }

        function usesCurrentApplicationServerKey(subscription) {
            var existing = subscription.options && subscription.options.applicationServerKey;

            if (! existing) {
                return false;
            }

            var expected = applicationServerKey(config.publicKey);
            var actual = new Uint8Array(existing);

            return actual.length === expected.length && actual.every(function (byte, index) {
                return byte === expected[index];
            });
        }

        function request(endpoint, method, body) {
            var csrf = document.querySelector('meta[name="csrf-token"]');

            return fetch(endpoint, {
                method: method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : '',
                },
                body: JSON.stringify(body),
            }).then(function (response) {
                if (response.ok) {
                    return response.status === 204
                        ? {}
                        : response.json().catch(function () {
                            return {};
                        });
                }

                return response.json().catch(function () {
                    return {};
                }).then(function (payload) {
                    throw new Error(payload.message || config.failedMessage);
                });
            });
        }

        function storeSubscription(subscription) {
            var key = subscription.getKey('p256dh');
            var token = subscription.getKey('auth');

            if (! key || ! token) {
                return Promise.reject(new Error(config.failedMessage));
            }

            return request(config.storeEndpoint, 'POST', {
                endpoint: subscription.endpoint,
                application_server_key: config.publicKey,
                keys: {
                    p256dh: base64Url(key),
                    auth: base64Url(token),
                },
                content_encoding: 'aes128gcm',
            });
        }

        function storeEnabledSubscription(subscription) {
            return storeSubscription(subscription).catch(function (failure) {
                var storedRemoved = false;

                // The POST may have reached the server even when its response
                // did not reach this page. Remove both halves so a failed
                // enable cannot strand an unusable local or server endpoint.
                pendingRemoval(subscription.endpoint);

                return request(config.destroyEndpoint, 'DELETE', {
                    endpoint: subscription.endpoint,
                }).then(function () {
                    storedRemoved = true;
                }).catch(function () {}).then(function () {
                    return subscription.unsubscribe().catch(function () {});
                }).then(function () {
                    if (storedRemoved) {
                        pendingRemoval(null);
                    }

                    throw failure;
                });
            });
        }

        function requestPushPermission() {
            if (Notification.permission === 'granted') {
                return Promise.resolve('granted');
            }

            try {
                // Invoke the browser prompt directly from the submit gesture.
                // Waiting for service-worker or ownership work first can spend
                // the transient user activation required by some browsers.
                return Notification.requestPermission();
            } catch (failure) {
                return Promise.reject(failure);
            }
        }

        function enablePush(permissionRequest) {
            return navigator.serviceWorker.register('/wayfindr-sw.js', { scope: '/' })
                .then(function (registration) {
                    return registration.pushManager.getSubscription().then(function (subscription) {
                        if (subscription
                            && usesCurrentApplicationServerKey(subscription)
                            && subscriptionOwnership !== 'foreign') {
                            return storeEnabledSubscription(subscription);
                        }

                        var removeStored = subscription && subscriptionOwnership === 'owned'
                            ? request(config.destroyEndpoint, 'DELETE', {
                                endpoint: subscription.endpoint,
                            }).catch(function () {})
                            : Promise.resolve();
                        var replace = subscription
                            ? removeStored.then(function () {
                                return subscription.unsubscribe();
                            })
                            : removeStored;

                        return replace.then(function () {
                            return permissionRequest.then(function (permission) {
                                if (permission !== 'granted') {
                                    throw new Error(config.failedMessage);
                                }

                                return registration.pushManager.subscribe({
                                    userVisibleOnly: true,
                                    applicationServerKey: applicationServerKey(config.publicKey),
                                });
                            });
                        }).then(storeEnabledSubscription);
                    });
                });
        }

        function disablePush() {
            return navigator.serviceWorker.getRegistration('/wayfindr-sw.js')
                .then(function (registration) {
                    return registration ? registration.pushManager.getSubscription() : null;
                })
                .then(function (subscription) {
                    if (! subscription) {
                        pendingRemoval(null);

                        return;
                    }

                    // Carry the browser endpoint into the ordinary preference
                    // update too. If this best-effort request flakes, the
                    // locked profile transaction can still remove exactly this
                    // agent's endpoint before checking for other browsers.
                    pendingRemoval(subscription.endpoint);

                    return request(config.destroyEndpoint, 'DELETE', {
                        endpoint: subscription.endpoint,
                    }).catch(function () {}).then(function () {
                        return subscription.unsubscribe();
                    });
                });
        }

        function cleanStaleSubscription(subscription, removeStored, requireLocalRemoval) {
            var storedRemoved = ! removeStored;

            if (removeStored) {
                // If the best-effort DELETE is throttled, carry the endpoint
                // into the next profile save so its locked transaction can
                // still remove only this agent's unusable subscription.
                pendingRemoval(subscription.endpoint);
            }

            var removal = removeStored
                ? request(config.destroyEndpoint, 'DELETE', {
                    endpoint: subscription.endpoint,
                }).then(function () {
                    storedRemoved = true;
                }).catch(function () {})
                : Promise.resolve();

            return removal
                .then(function () {
                    var localRemoval = subscription.unsubscribe();

                    if (! requireLocalRemoval) {
                        return localRemoval.catch(function () {});
                    }

                    return localRemoval
                        .then(function (unsubscribed) {
                            if (unsubscribed === false) {
                                throw new Error(config.ownedElsewhereCleanupFailedMessage);
                            }
                        })
                        .catch(function () {
                            throw new Error(config.ownedElsewhereCleanupFailedMessage);
                        });
                })
                .then(function () {
                    if (storedRemoved) {
                        pendingRemoval(null);
                    }

                    subscriptionOwnership = 'missing';
                    initialBrowserEnabled = false;
                    checkbox.checked = false;
                    checkbox.disabled = false;
                });
        }

        function subscriptionStatus(endpoint, attemptsRemaining) {
            return request(config.statusEndpoint, 'POST', {
                endpoint: endpoint,
            }).then(function (payload) {
                if (! ['owned', 'foreign', 'missing'].includes(payload.status)) {
                    throw new Error(config.failedMessage);
                }

                return payload;
            }).catch(function (failure) {
                if (attemptsRemaining <= 1) {
                    throw failure;
                }

                return subscriptionStatus(endpoint, attemptsRemaining - 1);
            });
        }

        function initializeBrowserState() {
            return navigator.serviceWorker.getRegistration('/wayfindr-sw.js')
                .then(function (registration) {
                    return registration ? registration.pushManager.getSubscription() : null;
                })
                .then(function (subscription) {
                    if (! subscription) {
                        subscriptionOwnership = 'missing';
                        initialBrowserEnabled = false;
                        checkbox.checked = false;
                        checkbox.disabled = false;

                        return;
                    }

                    return subscriptionStatus(subscription.endpoint, 2)
                        .then(function (payload) {
                            subscriptionOwnership = payload.status;
                            initialBrowserEnabled = payload.status === 'owned'
                                && usesCurrentApplicationServerKey(subscription);

                            if (payload.status === 'foreign') {
                                showError(config.ownedElsewhereMessage);

                                // This endpoint remains owned by the prior agent
                                // on the server, but it must stop receiving that
                                // agent's alerts in the browser now in use here.
                                return cleanStaleSubscription(subscription, false, true);
                            }

                            if (! usesCurrentApplicationServerKey(subscription)) {
                                return cleanStaleSubscription(subscription, payload.status === 'owned');
                            }

                            checkbox.checked = initialBrowserEnabled;
                            checkbox.disabled = false;
                        }).catch(function () {
                            // This page owns the lifecycle, so the global guard
                            // deliberately skips it. If ownership is still unknown
                            // after a bounded retry, remove the local subscription
                            // here rather than risk showing a prior agent's alerts.
                            showError(config.ownershipCheckFailedMessage);

                            return cleanStaleSubscription(subscription, false, true);
                        });
                })
                .catch(function (failure) {
                    browserStateAvailable = false;
                    preserveAndDisable(failure && failure.message);
                });
        }

        var browserStateAvailable = true;
        var initialBrowserEnabled = null;
        var subscriptionOwnership = 'missing';
        var browserStateReady = initializeBrowserState();

        form.addEventListener('submit', function (event) {
            if (form.dataset.pushSyncing === 'true') {
                return;
            }

            event.preventDefault();
            form.dataset.pushSyncing = 'true';

            if (error) {
                error.hidden = true;
            }

            var submitter = event.submitter;

            if (submitter) {
                submitter.disabled = true;
            }

            var pushPermission = null;

            if (browserStateAvailable
                && checkbox.checked
                && initialBrowserEnabled === false) {
                pushPermission = requestPushPermission();
                // Ownership initialization may still be settling. Mark a
                // prompt rejection handled immediately; enablePush consumes
                // this same promise and surfaces the failure to the form.
                pushPermission.catch(function () {});
            }

            var synchronization = browserStateReady.then(function () {
                if (! browserStateAvailable) {
                    return;
                }

                // Email, sound, or cadence saves must not touch browser push.
                // Synchronize only when this browser's own checkbox changed.
                if (checkbox.checked === initialBrowserEnabled) {
                    return;
                }

                return checkbox.checked
                    ? enablePush(pushPermission || Promise.resolve(Notification.permission))
                    : disablePush().catch(function (failure) {
                        // Once the exact endpoint is in the form, the locked
                        // profile update can finish server-side removal even
                        // when local unsubscribe flakes. Before that point we
                        // must surface the browser failure instead of claiming
                        // an opt-out that did not happen.
                        if (! form.querySelector('input[name="push_subscription_endpoint"]')) {
                            throw failure;
                        }
                    });
            });

            synchronization
                .then(function () {
                    HTMLFormElement.prototype.submit.call(form);
                })
                .catch(function (failure) {
                    form.dataset.pushSyncing = 'false';

                    if (submitter) {
                        submitter.disabled = false;
                    }

                    showError(failure.message);
                });
        });
    })();
</script>

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

        function preserveAndDisable() {
            var preserved = document.createElement('input');

            preserved.type = 'hidden';
            preserved.name = 'push_alerts';
            preserved.value = checkbox.checked ? '1' : '0';
            form.appendChild(preserved);
            checkbox.disabled = true;
            showError(config.unsupportedMessage);
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
                keys: {
                    p256dh: base64Url(key),
                    auth: base64Url(token),
                },
                content_encoding: 'aes128gcm',
            });
        }

        function enablePush() {
            return navigator.serviceWorker.register('/wayfindr-sw.js', { scope: '/' })
                .then(function (registration) {
                    return registration.pushManager.getSubscription().then(function (subscription) {
                        if (subscription
                            && usesCurrentApplicationServerKey(subscription)
                            && subscriptionOwnership !== 'foreign') {
                            return storeSubscription(subscription);
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
                            return Notification.requestPermission().then(function (permission) {
                                if (permission !== 'granted') {
                                    throw new Error(config.failedMessage);
                                }

                                return registration.pushManager.subscribe({
                                    userVisibleOnly: true,
                                    applicationServerKey: applicationServerKey(config.publicKey),
                                });
                            });
                        }).then(storeSubscription);
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

                    return request(config.statusEndpoint, 'POST', {
                        endpoint: subscription.endpoint,
                    }).then(function (payload) {
                        if (! ['owned', 'foreign', 'missing'].includes(payload.status)) {
                            throw new Error(config.failedMessage);
                        }

                        subscriptionOwnership = payload.status;
                        initialBrowserEnabled = payload.status === 'owned'
                            && usesCurrentApplicationServerKey(subscription);
                        checkbox.checked = initialBrowserEnabled;
                        checkbox.disabled = false;

                        if (payload.status === 'foreign') {
                            showError(config.ownedElsewhereMessage);
                        }
                    });
                })
                .catch(function () {
                    browserStateAvailable = false;
                    preserveAndDisable();
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
                    ? enablePush()
                    : disablePush().catch(function () {});
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

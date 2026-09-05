<script data-agent-push-logout-cleanup>
    (function () {
        var form = document.querySelector('form.wf-signout');
        var pushLifecycleLock = 'wayfindr:push-lifecycle';

        if (! form
            || ! window.isSecureContext
            || ! ('serviceWorker' in navigator)
            || ! ('PushManager' in window)) {
            return;
        }

        function submitLogoutFallback() {
            form.dataset.pushEndpointCaptured = 'true';
            HTMLFormElement.prototype.submit.call(form);
        }

        function requestLogout() {
            form.dataset.pushEndpointCaptured = 'true';

            return fetch(form.action, {
                method: (form.method || 'POST').toUpperCase(),
                credentials: 'same-origin',
                headers: {
                    Accept: 'text/html',
                },
                body: new FormData(form),
                redirect: 'follow',
            }).then(function (response) {
                if (! response.ok || ! response.redirected) {
                    throw new Error();
                }

                // Keep the browser lock until the authenticated request has
                // removed the captured endpoint and invalidated the session.
                // A waiting opt-in tab can only continue after that commit.
                window.location.assign(response.url);
            });
        }

        function appendEndpoint(subscription) {
            if (! subscription) {
                return subscription;
            }

            var endpoint = form.querySelector('input[name="push_subscription_endpoint"]');

            if (! endpoint) {
                endpoint = document.createElement('input');
                endpoint.type = 'hidden';
                endpoint.name = 'push_subscription_endpoint';
                form.appendChild(endpoint);
            }

            endpoint.value = subscription.endpoint;

            return subscription;
        }

        function captureEndpoint() {
            return navigator.serviceWorker.getRegistration('/wayfindr-sw.js')
                .then(function (registration) {
                    return registration ? registration.pushManager.getSubscription() : null;
                })
                .then(appendEndpoint);
        }

        form.addEventListener('submit', function (event) {
            if (form.dataset.pushEndpointCaptured === 'true') {
                return;
            }

            event.preventDefault();

            if (form.dataset.pushEndpointCapturePending === 'true') {
                return;
            }

            form.dataset.pushEndpointCapturePending = 'true';

            var captureAndRequestLogout = function () {
                return captureEndpoint()
                    .catch(function () {
                        // A previously captured endpoint remains on the form.
                    })
                    .then(requestLogout);
            };

            if (! navigator.locks || typeof navigator.locks.request !== 'function') {
                // Wayfindr does not create new subscriptions without Web Locks,
                // so this compatibility path has no concurrent in-app opt-in.
                captureEndpoint()
                    .catch(function () {
                        // A previously captured endpoint remains on the form.
                    })
                    .then(submitLogoutFallback);

                return;
            }

            // Use the same origin-wide lifecycle lock as opt-in. Capturing the
            // current endpoint and completing logout are one serialized unit,
            // including when no subscription exists at click time.
            navigator.locks.request(
                pushLifecycleLock,
                { mode: 'exclusive' },
                captureAndRequestLogout
            )
                .catch(function () {
                    form.dataset.pushEndpointCaptured = 'false';
                    form.dataset.pushEndpointCapturePending = 'false';
                });
        });
    })();
</script>

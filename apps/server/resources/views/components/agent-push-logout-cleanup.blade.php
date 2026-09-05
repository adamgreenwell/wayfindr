<script data-agent-push-logout-cleanup>
    (function () {
        var form = document.querySelector('form.wf-signout');

        if (! form
            || ! window.isSecureContext
            || ! ('serviceWorker' in navigator)
            || ! ('PushManager' in window)) {
            return;
        }

        function submitLogout() {
            form.dataset.pushEndpointCaptured = 'true';
            HTMLFormElement.prototype.submit.call(form);
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

        // Start while the authenticated page is idle. In the common case the
        // exact endpoint is already attached to the form before logout begins.
        // A rejected lookup remains retryable when the user does sign out.
        var eagerLookupPending = true;
        var endpointLookup = captureEndpoint()
            .catch(function () {
                return null;
            })
            .then(function (subscription) {
                eagerLookupPending = false;

                return subscription;
            });

        form.addEventListener('submit', function (event) {
            if (form.dataset.pushEndpointCaptured === 'true') {
                return;
            }

            event.preventDefault();

            if (form.dataset.pushEndpointCapturePending === 'true') {
                return;
            }

            form.dataset.pushEndpointCapturePending = 'true';

            // If the eager lookup is still running, it is already the freshest
            // possible answer. Once it has settled, recheck in case this page
            // opted in or replaced its subscription after the initial capture.
            var logoutLookup = eagerLookupPending ? endpointLookup : captureEndpoint();

            logoutLookup
                .catch(function () {
                    // A previously captured endpoint remains on the form. If
                    // none exists, the logged-out page still cleans up locally.
                })
                .then(submitLogout);
        });
    })();
</script>

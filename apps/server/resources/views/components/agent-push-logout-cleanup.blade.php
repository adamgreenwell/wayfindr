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

        form.addEventListener('submit', function (event) {
            if (form.dataset.pushEndpointCaptured === 'true') {
                return;
            }

            event.preventDefault();

            if (form.dataset.pushEndpointCapturePending === 'true') {
                return;
            }

            form.dataset.pushEndpointCapturePending = 'true';

            var lookup = navigator.serviceWorker.getRegistration('/wayfindr-sw.js')
                .then(function (registration) {
                    return registration ? registration.pushManager.getSubscription() : null;
                });
            var timeout = new Promise(function (resolve) {
                window.setTimeout(function () {
                    resolve(null);
                }, 1000);
            });

            Promise.race([lookup, timeout])
                .then(function (subscription) {
                    if (! subscription) {
                        return;
                    }

                    var endpoint = document.createElement('input');

                    endpoint.type = 'hidden';
                    endpoint.name = 'push_subscription_endpoint';
                    endpoint.value = subscription.endpoint;
                    form.appendChild(endpoint);
                })
                .catch(function () {
                    // The logged-out page still performs local-only cleanup.
                })
                .then(submitLogout);
        });
    })();
</script>

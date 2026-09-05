@props(['config'])

<script data-agent-alert-stream>
    (function () {
        var config = @json($config);
        var originalTitle = document.title;
        var favicon = document.querySelector('[data-agent-alert-favicon]');
        var originalFavicon = favicon ? favicon.getAttribute('href') : null;
        var pendingAlertIds = {};
        var pendingAlertCount = 0;
        var audioContext = null;

        function isBackground() {
            return document.visibilityState === 'hidden' || ! document.hasFocus();
        }

        function attentionFavicon() {
            var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
                + '<rect width="32" height="32" rx="6" fill="#16181A"/>'
                + '<path d="M7 22L14 8l3 7 3-5 5 12H7z" fill="#F1F1EE"/>'
                + '<circle cx="25" cy="7" r="5" fill="#C3352B" stroke="#F1F1EE" stroke-width="2"/>'
                + '</svg>';

            return 'data:image/svg+xml,' + encodeURIComponent(svg);
        }

        function renderAttention() {
            var count = pendingAlertCount > 99 ? '99+' : String(pendingAlertCount);

            document.title = '(' + count + ') ' + originalTitle;

            if (favicon) {
                favicon.setAttribute('href', attentionFavicon());
                favicon.setAttribute('data-agent-alert-state', 'attention');
            }
        }

        function clearAttention() {
            pendingAlertIds = {};
            pendingAlertCount = 0;
            document.title = originalTitle;

            if (favicon) {
                if (originalFavicon === null) {
                    favicon.removeAttribute('href');
                } else {
                    favicon.setAttribute('href', originalFavicon);
                }

                favicon.removeAttribute('data-agent-alert-state');
            }
        }

        function clearAttentionIfForeground() {
            if (! isBackground()) {
                clearAttention();
            }
        }

        function audioContextConstructor() {
            return window.AudioContext || window.webkitAudioContext || null;
        }

        function armSound() {
            if (! config.soundEnabled || audioContext) {
                return;
            }

            var AudioContextConstructor = audioContextConstructor();

            if (! AudioContextConstructor) {
                return;
            }

            try {
                audioContext = new AudioContextConstructor();

                if (audioContext.state === 'suspended') {
                    audioContext.resume().catch(function () {
                        // The title and favicon remain the dependable cue when
                        // a browser keeps audio locked behind another gesture.
                    });
                }
            } catch (error) {
                audioContext = null;
            }
        }

        function playSound() {
            if (! config.soundEnabled || ! audioContext || audioContext.state !== 'running') {
                return;
            }

            try {
                var oscillator = audioContext.createOscillator();
                var gain = audioContext.createGain();
                var startsAt = audioContext.currentTime;

                oscillator.type = 'sine';
                oscillator.frequency.setValueAtTime(660, startsAt);
                gain.gain.setValueAtTime(0.0001, startsAt);
                gain.gain.exponentialRampToValueAtTime(0.055, startsAt + 0.015);
                gain.gain.exponentialRampToValueAtTime(0.0001, startsAt + 0.16);
                oscillator.connect(gain);
                gain.connect(audioContext.destination);
                oscillator.start(startsAt);
                oscillator.stop(startsAt + 0.17);
            } catch (error) {
                // Sound is optional. Never let an audio implementation fault
                // suppress the title, favicon, or underlying database alert.
            }
        }

        function announceAlert(alert) {
            if (! alert || typeof alert.id !== 'string' || ! isBackground()) {
                return;
            }

            if (! pendingAlertIds[alert.id]) {
                pendingAlertIds[alert.id] = true;
                pendingAlertCount++;
            }

            renderAttention();
            playSound();
        }

        document.addEventListener('wayfindr:agent-alert-stored', function (event) {
            announceAlert(event.detail ? event.detail.alert : null);
        });
        document.addEventListener('visibilitychange', clearAttentionIfForeground);
        window.addEventListener('focus', clearAttentionIfForeground);

        if (config.soundEnabled) {
            document.addEventListener('pointerdown', armSound, { once: true });
            document.addEventListener('keydown', armSound, { once: true });
        }

        if (! window.WebSocket) {
            return;
        }

        var socketScheme = config.scheme === 'https' ? 'wss' : 'ws';
        var socketUrl = socketScheme + '://' + config.host + ':' + config.port + '/app/'
            + encodeURIComponent(config.appKey) + '?protocol=7&client=wayfindr-alerts&version=0.0.0&flash=false';
        var socket = null;
        var socketGeneration = 0;
        var reconnectDelay = 1000;
        var reconnectTimer = null;
        var keepaliveTimer = null;
        var pageClosing = false;

        function parsePayload(value) {
            if (typeof value !== 'string') {
                return value || {};
            }

            try {
                return JSON.parse(value);
            } catch (error) {
                return {};
            }
        }

        function stopKeepalive() {
            if (keepaliveTimer) {
                window.clearInterval(keepaliveTimer);
                keepaliveTimer = null;
            }
        }

        function startKeepalive(activeSocket, activityTimeoutSeconds) {
            stopKeepalive();

            var declared = Number(activityTimeoutSeconds);
            var every = Math.max(5, Math.min(25, (declared > 0 ? declared : 30) / 2));

            keepaliveTimer = window.setInterval(function () {
                if (activeSocket.readyState !== 1) {
                    stopKeepalive();

                    return;
                }

                try {
                    activeSocket.send(JSON.stringify({ event: 'pusher:ping', data: {} }));
                } catch (error) {
                    stopKeepalive();
                }
            }, every * 1000);
        }

        function scheduleReconnect() {
            if (pageClosing || reconnectTimer) {
                return;
            }

            reconnectTimer = window.setTimeout(function () {
                reconnectTimer = null;
                connect();
            }, reconnectDelay);
            reconnectDelay = Math.min(reconnectDelay * 2, 15000);
        }

        function authorization(socketId, channelName) {
            var csrf = document.querySelector('meta[name="csrf-token"]');

            return fetch(config.authEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : '',
                },
                body: new URLSearchParams({
                    socket_id: socketId,
                    channel_name: channelName,
                }).toString(),
            }).then(function (response) {
                if (! response.ok) {
                    var failure = new Error('Broadcast authorization failed.');

                    failure.status = response.status;
                    throw failure;
                }

                return response.json();
            });
        }

        function subscribe(activeSocket, channelName, authorized) {
            if (activeSocket.readyState !== 1) {
                return;
            }

            var data = { auth: authorized.auth, channel: channelName };

            if (authorized.channel_data) {
                data.channel_data = authorized.channel_data;
            }

            activeSocket.send(JSON.stringify({
                event: 'pusher:subscribe',
                data: data,
            }));
        }

        function authorizationFailed(activeSocket, error) {
            if (activeSocket.wayfindrGeneration !== socketGeneration) {
                return;
            }

            if (error && [401, 403, 419].indexOf(error.status) !== -1) {
                pageClosing = true;
                window.location.reload();

                return;
            }

            try {
                activeSocket.close();
            } catch (closeError) {
                scheduleReconnect();
            }
        }

        function authorizeChannel(activeSocket, channelName) {
            authorization(activeSocket.wayfindrSocketId, channelName)
                .then(function (authorized) {
                    if (activeSocket.wayfindrGeneration === socketGeneration) {
                        subscribe(activeSocket, channelName, authorized);
                    }
                })
                .catch(function (error) {
                    authorizationFailed(activeSocket, error);
                });
        }

        function handleSocketMessage(message) {
            var event = parsePayload(message.data);

            if (event.event === 'pusher:ping') {
                try {
                    message.target.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
                } catch (error) {
                    // The close handler owns reconnecting a socket that is gone.
                }

                return;
            }

            if (event.event === 'pusher:connection_established') {
                var established = parsePayload(event.data);

                message.target.wayfindrSocketId = established.socket_id;
                startKeepalive(message.target, established.activity_timeout);
                authorizeChannel(message.target, config.identityChannelName);

                return;
            }

            if (event.event === 'pusher_internal:subscription_succeeded') {
                if (event.channel === config.identityChannelName) {
                    authorizeChannel(message.target, config.channelName);
                } else if (event.channel === config.channelName) {
                    reconnectDelay = 1000;
                }

                return;
            }

            if (event.event === config.eventName && event.channel === config.channelName) {
                var payload = parsePayload(event.data);
                var alert = payload && payload.alert;

                if (! alert || typeof alert.id !== 'string' || typeof alert.data !== 'object') {
                    return;
                }

                document.dispatchEvent(new CustomEvent('wayfindr:agent-alert-stored', {
                    detail: { alert: alert },
                }));
            }
        }

        function connect() {
            var generation = ++socketGeneration;

            try {
                socket = new WebSocket(socketUrl);
            } catch (error) {
                scheduleReconnect();

                return;
            }

            socket.wayfindrGeneration = generation;
            socket.addEventListener('message', handleSocketMessage);
            socket.addEventListener('close', function () {
                if (generation !== socketGeneration) {
                    return;
                }

                stopKeepalive();
                scheduleReconnect();
            });
            socket.addEventListener('error', function () {
                // A close event follows and owns the reconnect.
            });
        }

        window.addEventListener('beforeunload', function () {
            pageClosing = true;
            stopKeepalive();

            if (reconnectTimer) {
                window.clearTimeout(reconnectTimer);
            }

            if (socket) {
                try {
                    socket.close();
                } catch (error) {
                    // Teardown is best effort.
                }
            }
        });

        connect();
    })();
</script>

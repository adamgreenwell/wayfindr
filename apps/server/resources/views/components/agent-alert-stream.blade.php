@props(['config'])

<script data-agent-alert-stream>
    (function () {
        var config = @json($config);
        var originalTitle = document.title;
        var favicon = document.querySelector('[data-agent-alert-favicon]');
        var originalFavicon = favicon ? favicon.getAttribute('href') : null;
        var pendingAlertIds = {};
        var pendingAlertCount = 0;
        var seenAlertVersions = {};
        var seenAlertVersionOrder = [];
        var liveAlertVersionKeepUntil = {};
        var liveAlertVersionRetentionMilliseconds = 5 * 60 * 1000;
        var reconcileSince = config.reconcileSince;
        var reconcileThrough = null;
        var reconcileCursor = null;
        var reconcileSoundPlayed = false;
        var audioContext = null;
        var soundGateRequest = null;

        (config.knownAlerts || []).forEach(function (alert) {
            rememberAlertVersion(alert.version, alert.alertedAt);
        });

        function rememberAlertVersion(version, alertedAt, liveDelivery) {
            var milliseconds = Date.parse(alertedAt);

            if (typeof version !== 'string'
                || ! Number.isFinite(milliseconds)) {
                return false;
            }

            if (liveDelivery) {
                // A rolling-release repair can publish an alert whose durable
                // timestamp is old. Retain its just-delivered version by local
                // receipt time long enough to dedupe every queued retry.
                liveAlertVersionKeepUntil[version] = Date.now()
                    + liveAlertVersionRetentionMilliseconds;
            }

            if (seenAlertVersions[version] !== undefined) {
                return false;
            }

            seenAlertVersions[version] = milliseconds;
            seenAlertVersionOrder.push({
                milliseconds: milliseconds,
                version: version,
            });

            return true;
        }

        function pruneAlertVersions(keepSince) {
            var cutoff = Date.parse(keepSince);
            var now = Date.now();

            if (! Number.isFinite(cutoff)) {
                return;
            }

            seenAlertVersionOrder = seenAlertVersionOrder.filter(function (entry) {
                if (entry.milliseconds >= cutoff
                    || (liveAlertVersionKeepUntil[entry.version] || 0) > now) {
                    return true;
                }

                if (seenAlertVersions[entry.version] === entry.milliseconds) {
                    delete seenAlertVersions[entry.version];
                    delete liveAlertVersionKeepUntil[entry.version];
                }

                return false;
            });
        }

        function overlappingReconcileSince(watermark) {
            var milliseconds = Date.parse(watermark);
            var overlapSeconds = Number(config.reconcileOverlapSeconds);

            if (! Number.isFinite(milliseconds)
                || ! Number.isFinite(overlapSeconds)
                || overlapSeconds < 0) {
                throw new Error('Alert reconciliation returned an invalid watermark.');
            }

            return new Date(milliseconds - (overlapSeconds * 1000)).toISOString();
        }

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

        function quietTimeMinutes(value) {
            var match = typeof value === 'string'
                ? value.match(/^([01]\d|2[0-3]):([0-5]\d)$/)
                : null;

            return match ? (Number(match[1]) * 60) + Number(match[2]) : null;
        }

        function quietHoursActive() {
            var quietHours = config.quietHours;

            if (! quietHours || quietHours.enabled !== true) {
                return false;
            }

            try {
                var parts = new Intl.DateTimeFormat('en-GB', {
                    hour: '2-digit',
                    hour12: false,
                    hourCycle: 'h23',
                    minute: '2-digit',
                    timeZone: quietHours.timezone,
                }).formatToParts(new Date());
                var values = {};

                parts.forEach(function (part) {
                    values[part.type] = part.value;
                });

                var current = quietTimeMinutes(values.hour + ':' + values.minute);
                var start = quietTimeMinutes(quietHours.start);
                var end = quietTimeMinutes(quietHours.end);

                if (current === null || start === null || end === null || start === end) {
                    // A configured quiet period should fail silent in an older
                    // browser rather than unexpectedly waking the agent.
                    return true;
                }

                return start < end
                    ? current >= start && current < end
                    : current >= start || current < end;
            } catch (error) {
                return true;
            }
        }

        function refreshSoundGate() {
            if (soundGateRequest) {
                return soundGateRequest;
            }

            soundGateRequest = window.fetch(config.soundGateEndpoint, {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                },
            }).then(function (response) {
                if (! response.ok) {
                    throw new Error('Alert sound preferences could not be refreshed.');
                }

                return response.json();
            }).then(function (payload) {
                var data = payload && payload.data;

                if (! data
                    || typeof data.interruptions_paused !== 'boolean'
                    || typeof data.sound_enabled !== 'boolean'
                    || ! data.quiet_hours
                    || typeof data.quiet_hours !== 'object') {
                    throw new Error('Alert sound preferences were invalid.');
                }

                config.quietHours = data.quiet_hours;
                config.soundEnabled = data.sound_enabled;

                return config.soundEnabled && ! data.interruptions_paused;
            }).catch(function () {
                // An unverified sound decision fails silent. The durable title
                // and favicon attention cues still update normally.
                return false;
            }).finally(function () {
                soundGateRequest = null;
            });

            return soundGateRequest;
        }

        function armSound() {
            if (audioContext) {
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
            if (! audioContext
                || audioContext.state !== 'running') {
                return;
            }

            refreshSoundGate().then(function (soundAllowed) {
                if (! soundAllowed || quietHoursActive()) {
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
            });
        }

        function announceAlert(alert, audible) {
            if (! alert
                || typeof alert.id !== 'string'
                || typeof alert.version !== 'string'
                || ! alert.data
                || typeof alert.data !== 'object'
                || ! rememberAlertVersion(alert.version, alert.alerted_at, audible)) {
                return false;
            }

            // Remember foreground deliveries too. If this exact durable state
            // appears in an overlapping catch-up after a later reconnect, it
            // is not a new reason to pull the agent back to the tab.
            if (! isBackground()) {
                return false;
            }

            if (! pendingAlertIds[alert.id]) {
                pendingAlertIds[alert.id] = true;
                pendingAlertCount++;
            }

            renderAttention();

            if (audible) {
                playSound();
            }

            return true;
        }

        document.addEventListener('wayfindr:agent-alert-stored', function (event) {
            var alert = event.detail ? event.detail.alert : null;

            announceAlert(alert, true);
            pruneAlertVersions(overlappingReconcileSince(new Date().toISOString()));
        });
        function foregroundStateChanged() {
            clearAttentionIfForeground();
        }

        document.addEventListener('visibilitychange', foregroundStateChanged);
        window.addEventListener('focus', foregroundStateChanged);

        document.addEventListener('pointerdown', armSound, { once: true });
        document.addEventListener('keydown', armSound, { once: true });

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

        function retryAfterMilliseconds(value) {
            if (typeof value === 'string' && value.trim() !== '') {
                var seconds = Number(value);

                if (Number.isFinite(seconds) && seconds >= 0) {
                    return Math.max(1000, seconds * 1000);
                }

                var retryAt = Date.parse(value);

                if (Number.isFinite(retryAt)) {
                    return Math.max(1000, retryAt - Date.now());
                }
            }

            return 60 * 1000;
        }

        function clearReconcileRetry(activeSocket) {
            if (activeSocket && activeSocket.wayfindrReconcileRetryTimer) {
                window.clearTimeout(activeSocket.wayfindrReconcileRetryTimer);
                activeSocket.wayfindrReconcileRetryTimer = null;
            }
        }

        function scheduleReconcileRetry(activeSocket, delayMilliseconds) {
            if (pageClosing
                || activeSocket.wayfindrGeneration !== socketGeneration
                || activeSocket.readyState !== 1) {
                activeSocket.wayfindrReconciling = false;

                return;
            }

            clearReconcileRetry(activeSocket);
            activeSocket.wayfindrReconcileRetryTimer = window.setTimeout(function () {
                activeSocket.wayfindrReconcileRetryTimer = null;

                if (pageClosing
                    || activeSocket.wayfindrGeneration !== socketGeneration
                    || activeSocket.readyState !== 1) {
                    activeSocket.wayfindrReconciling = false;

                    return;
                }

                reconcileAlertPage(activeSocket);
            }, delayMilliseconds);
        }

        function reconcileAlertPage(activeSocket) {
            var parameters = { since: reconcileSince };

            if (reconcileThrough) {
                parameters.through = reconcileThrough;
            }

            if (reconcileCursor) {
                parameters.cursor = reconcileCursor;
            }

            var endpoint = config.reconcileEndpoint + '?'
                + new URLSearchParams(parameters).toString();

            return fetch(endpoint, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            }).then(function (response) {
                if (! response.ok) {
                    var failure = new Error('Alert reconciliation failed.');

                    failure.status = response.status;

                    if (response.status === 429) {
                        failure.retryAfterMilliseconds = retryAfterMilliseconds(
                            response.headers.get('Retry-After')
                        );
                    }

                    throw failure;
                }

                return response.json();
            }).then(function (response) {
                if (activeSocket.wayfindrGeneration !== socketGeneration) {
                    return;
                }

                var data = response && response.data;

                if (! data
                    || ! Array.isArray(data.alerts)
                    || typeof data.truncated !== 'boolean'
                    || typeof data.watermark !== 'string'
                    || (data.next_cursor !== null && typeof data.next_cursor !== 'string')
                    || data.truncated !== (typeof data.next_cursor === 'string')) {
                    throw new Error('Alert reconciliation returned an invalid response.');
                }

                var shouldPlaySound = data.alerts.reduce(function (announced, alert) {
                    return announceAlert(alert, false) || announced;
                }, false);

                reconcileThrough = data.watermark;
                reconcileCursor = data.next_cursor;

                // One paginated catch-up, one tone. A reconnect after a longer
                // outage must not turn a backlog into a burst of beeps.
                if (shouldPlaySound && ! reconcileSoundPlayed) {
                    playSound();
                    reconcileSoundPlayed = true;
                }

                if (reconcileCursor) {
                    return reconcileAlertPage(activeSocket);
                }

                // An alert may receive its database timestamp shortly before
                // its transaction commits. Retain a small overlap so a socket
                // gap at that boundary cannot strand the durable alert behind
                // the watermark; payload versions suppress repeated cues.
                reconcileSince = overlappingReconcileSince(data.watermark);
                pruneAlertVersions(reconcileSince);
                reconcileThrough = null;
                reconcileSoundPlayed = false;
                activeSocket.wayfindrReconciling = false;

                // A reconnect can resume a cursor whose upper watermark was
                // frozen on the previous socket. Finish that bounded walk, then
                // immediately sweep through the present so alerts committed
                // during the second gap are not stranded until another outage.
                if (activeSocket.wayfindrNeedsFreshReconcile) {
                    activeSocket.wayfindrNeedsFreshReconcile = false;
                    reconcileAlerts(activeSocket);

                    return;
                }

            }).catch(function (error) {
                if (error && error.status === 429) {
                    scheduleReconcileRetry(activeSocket, error.retryAfterMilliseconds);

                    return;
                }

                activeSocket.wayfindrReconciling = false;
                authorizationFailed(activeSocket, error);
            });
        }

        function reconcileAlerts(activeSocket) {
            if (activeSocket.wayfindrReconciling) {
                return;
            }

            activeSocket.wayfindrNeedsFreshReconcile = Boolean(reconcileThrough || reconcileCursor);
            activeSocket.wayfindrReconciling = true;
            reconcileAlertPage(activeSocket);
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
                    reconcileAlerts(message.target);
                }

                return;
            }

            if (event.event === config.eventName && event.channel === config.channelName) {
                var payload = parsePayload(event.data);
                var alert = payload && payload.alert;

                if (! alert
                    || typeof alert.id !== 'string'
                    || typeof alert.version !== 'string'
                    || typeof alert.alerted_at !== 'string'
                    || ! alert.data
                    || typeof alert.data !== 'object') {
                    return;
                }

                if (document.visibilityState === 'visible') {
                    var csrf = document.querySelector('meta[name="csrf-token"]');

                    // Only an actual, visible socket delivery writes this exact
                    // alert-version receipt. Presence membership can outlive a
                    // dead connection, so it is never used to suppress Web Push.
                    fetch(config.realtimeReceiptEndpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        keepalive: true,
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : '',
                        },
                        body: JSON.stringify({
                            alert_id: alert.id,
                            version: alert.version,
                        }),
                    }).catch(function () {
                        // Missing a receipt merely allows the Web Push fallback.
                    });
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
            socket.addEventListener('close', function (event) {
                clearReconcileRetry(event.currentTarget);

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
                clearReconcileRetry(socket);

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

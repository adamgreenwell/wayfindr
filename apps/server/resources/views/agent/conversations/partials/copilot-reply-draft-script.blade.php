<script>
    (() => {
        let attempts = 0;
        let refreshInFlight = false;
        let forcedRefreshQueued = false;
        // Cover the five-minute queued freshness window plus the 85-second
        // running-job budget, with enough room for the terminal poll.
        const maximumAttempts = 210;

        const refresh = async (force = false) => {
            const panel = document.querySelector('[data-copilot-reply-draft]');
            const pending = panel?.dataset.state === 'pending';

            if (!panel || (pending && attempts >= maximumAttempts)) {
                return;
            }

            if (refreshInFlight) {
                forcedRefreshQueued = forcedRefreshQueued || force;

                return;
            }

            if (pending) {
                attempts += 1;
            }

            refreshInFlight = true;

            try {
                const response = await fetch(panel.dataset.statusUrl, {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'text/html',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if ([204, 403, 404, 410].includes(response.status)) {
                    panel.remove();
                    window.wayfindrAssistDraftReview?.sync('reply');

                    return;
                }

                if (!response.ok) {
                    throw new Error('Reply draft refresh failed.');
                }

                const markup = await response.text();

                if (panel.isConnected) {
                    panel.outerHTML = markup;
                    window.wayfindrAssistDraftReview?.sync('reply');
                }
            } catch (error) {
                // Leave the current state in place. A page refresh remains a
                // safe recovery path for transient polling failures.
            } finally {
                refreshInFlight = false;

                if (forcedRefreshQueued) {
                    forcedRefreshQueued = false;
                    void refresh(true);

                    return;
                }

                const currentPanel = document.querySelector('[data-copilot-reply-draft]');

                if (currentPanel) {
                    // Ready output is re-authorized too, so policy edits withdraw it.
                    window.setTimeout(() => refresh(), currentPanel.dataset.state === 'pending' ? 2000 : 10000);
                }
            }
        };

        // An input revision also catches typing followed by deleting during fetch.
        const bodyEdits = new WeakMap();
        document.addEventListener('input', (event) => {
            if (event.target instanceof Element && event.target.matches('[data-reply-body]')) {
                bodyEdits.set(event.target, (bodyEdits.get(event.target) ?? 0) + 1);
            }
        });

        document.addEventListener('click', async (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const useButton = target?.closest('[data-copilot-reply-draft-use]');
            if (!useButton || useButton.disabled) {
                return;
            }
            const panel = useButton.closest('[data-copilot-reply-draft]');
            const replyShell = useButton.closest('[data-reply-shell]');
            const body = replyShell?.querySelector('[data-reply-body]');
            const status = replyShell?.querySelector('[data-reply-status]');
            const staleNotice = panel?.querySelector('[data-copilot-reply-draft-stale]');
            if (!body || !panel?.dataset.useUrl || !panel.dataset.generation) {
                return;
            }
            if (staleNotice && !staleNotice.hidden) {
                if (status) status.textContent = @json(__('conversations.detail.reply_copilot.use_stale'));
                return;
            }
            if (window.wayfindrAssistDraftReview) {
                const generation = panel.dataset.generation;
                const policyRevision = panel.dataset.policyRevision || '';
                const sourcePosition = panel.dataset.sourcePosition || '';
                const query = new URLSearchParams({generation});
                void window.wayfindrAssistDraftReview.offer({shell:replyShell, task:'reply',
                    readCurrent:() => {
                        const currentPanel = replyShell.querySelector('[data-copilot-reply-draft]');
                        if (!currentPanel || currentPanel.closest('[hidden]')) return null;
                        return {canUse:currentPanel.dataset.state === 'ready' && !currentPanel.querySelector('[data-copilot-reply-draft-stale]:not([hidden])'),
                            generation:currentPanel.dataset.generation, policyRevision:currentPanel.dataset.policyRevision,
                            sourcePosition:currentPanel.dataset.sourcePosition || '', outputGeneration:currentPanel.dataset.generation,
                             button:currentPanel.querySelector('[data-copilot-reply-draft-use]')};
                    },
                    fetchAuthorized:async () => {
                        const response = await fetch(`${panel.dataset.useUrl}?${query}`, {
                            credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
                        });
                        if (!response.ok) {
                            if ([403,404,410].includes(response.status)) {
                                const denied = replyShell.querySelector('[data-copilot-reply-draft]');
                                if (denied?.dataset.generation === generation && (denied.dataset.policyRevision || '') === policyRevision) denied.remove();
                                window.wayfindrAssistDraftReview.sync('reply');
                            }
                            throw new Error('Unavailable');
                        }
                        const payload = await response.json();
                        return {canUse:true, generation:payload.generation, policyRevision:payload.policy_revision || '',
                            sourcePosition, outputGeneration:payload.generation,  text:payload.text};
                    }
                });
                return;
            }
            if (body.value.trim() !== '') {
                if (status) status.textContent = @json(__('conversations.detail.reply_copilot.use_blocked'));
                body.focus();
                return;
            }
            const generation = panel.dataset.generation;
            const policyRevision = panel.dataset.policyRevision;
            const initialEdits = bodyEdits.get(body) ?? 0;
            const initialValue = body.value;
            const query = new URLSearchParams({ generation });

            useButton.disabled = true;
            try {
                const response = await fetch(`${panel.dataset.useUrl}?${query}`, {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) {
                    if ([403, 404, 410].includes(response.status)) panel.remove();
                    throw new Error('Suggestion is no longer available.');
                }
                const payload = await response.json();
                if (!panel.isConnected || document.querySelector('[data-copilot-reply-draft]') !== panel
                    || panel.dataset.generation !== generation || panel.dataset.policyRevision !== policyRevision
                    || payload.generation !== generation || (payload.policy_revision ?? '') !== policyRevision
                    || (staleNotice && !staleNotice.hidden)) {
                    return;
                }
                // Recheck after the server reply; never replace an edit made meanwhile.
                if ((bodyEdits.get(body) ?? 0) !== initialEdits || body.value !== initialValue || body.value.trim() !== '') {
                    if (status) status.textContent = @json(__('conversations.detail.reply_copilot.use_blocked'));
                    body.focus();
                    return;
                }
                if (typeof payload.text !== 'string' || !payload.text.trim()) {
                    throw new Error('Suggestion is no longer available.');
                }
                const templatePicker = replyShell.querySelector('[data-template-picker]');
                if (templatePicker) {
                    templatePicker.value = '';
                    templatePicker.dispatchEvent(new Event('change', { bubbles: true }));
                }
                body.value = payload.text.trim();
                body.setAttribute('lang', '');
                body.dispatchEvent(new Event('input', { bubbles: true }));
                body.focus();
                if (status) status.textContent = @json(__('conversations.detail.reply_copilot.used'));
            } catch (error) {
                if (staleNotice) staleNotice.hidden = false;
                if (status) status.textContent = @json(__('conversations.detail.reply_copilot.use_stale'));
            } finally {
                if (panel.isConnected && (!staleNotice || staleNotice.hidden)) useButton.disabled = false;
            }
        });

        window.wayfindrConversationReplyDraftTranscriptUpdated = () => {
            const panel = document.querySelector('[data-copilot-reply-draft]');

            if (!panel || panel.dataset.state !== 'ready') {
                return;
            }

            const staleNotice = panel.querySelector('[data-copilot-reply-draft-stale]');
            const useButton = panel.querySelector('[data-copilot-reply-draft-use]');

            if (staleNotice) {
                staleNotice.hidden = false;
            }

            if (useButton) {
                useButton.disabled = true;
            }

            attempts = 0;
            void refresh(true);
        };

        window.setTimeout(() => refresh(), 1200);
    })();
</script>

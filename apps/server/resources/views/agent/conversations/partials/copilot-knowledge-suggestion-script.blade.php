<script>
    (() => {
        let attempts = 0;
        let refreshInFlight = false;
        let forcedRefreshQueued = false;
        // Cover the five-minute queued freshness window plus the 85-second
        // running-job budget, with enough room for the terminal poll.
        const maximumAttempts = 210;

        const refresh = async (force = false) => {
            const panel = document.querySelector('[data-copilot-knowledge-suggestion]');
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

                    return;
                }

                if (!response.ok) {
                    throw new Error('Knowledge suggestion refresh failed.');
                }

                const markup = await response.text();

                if (panel.isConnected) {
                    panel.outerHTML = markup;
                }
            } catch (error) {
                // The page remains usable and a manual refresh can recover.
            } finally {
                refreshInFlight = false;

                if (forcedRefreshQueued) {
                    forcedRefreshQueued = false;
                    void refresh(true);

                    return;
                }

                const currentPanel = document.querySelector('[data-copilot-knowledge-suggestion]');

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
            const useButton = target?.closest('[data-copilot-knowledge-use]');
            if (!useButton || useButton.disabled) {
                return;
            }
            const panel = useButton.closest('[data-copilot-knowledge-suggestion]');
            const replyShell = useButton.closest('[data-reply-shell]');
            const body = replyShell?.querySelector('[data-reply-body]');
            const status = panel?.querySelector('[data-copilot-knowledge-status]');
            const staleNotice = panel?.querySelector('[data-copilot-knowledge-suggestion-stale]');
            if (!body || !panel?.dataset.useUrl || !panel.dataset.generation) {
                return;
            }
            if (staleNotice && !staleNotice.hidden) {
                if (status) status.textContent = @json(__('conversations.detail.knowledge_copilot.use_stale'));
                return;
            }
            if (body.value.trim() !== '') {
                if (status) status.textContent = @json(__('conversations.detail.knowledge_copilot.use_blocked'));
                body.focus();
                return;
            }
            const generation = panel.dataset.generation;
            const policyRevision = panel.dataset.policyRevision;
            const initialEdits = bodyEdits.get(body) ?? 0;
            const initialValue = body.value;
            const query = new URLSearchParams({ generation });
            const article = useButton.closest('[data-copilot-knowledge-article]');
            if (!article?.dataset.articleId || !article.dataset.articleRevision) {
                return;
            }
            query.set('article_id', article.dataset.articleId);
            query.set('article_revision', article.dataset.articleRevision);

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
                if (!panel.isConnected || document.querySelector('[data-copilot-knowledge-suggestion]') !== panel
                    || panel.dataset.generation !== generation || panel.dataset.policyRevision !== policyRevision
                    || payload.generation !== generation || (payload.policy_revision ?? '') !== policyRevision
                    || (staleNotice && !staleNotice.hidden)) {
                    return;
                }
                // Recheck after the server reply; never replace an edit made meanwhile.
                if ((bodyEdits.get(body) ?? 0) !== initialEdits || body.value !== initialValue || body.value.trim() !== '') {
                    if (status) status.textContent = @json(__('conversations.detail.knowledge_copilot.use_blocked'));
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
                if (status) status.textContent = @json(__('conversations.detail.knowledge_copilot.used'));
            } catch (error) {
                if (staleNotice) staleNotice.hidden = false;
                if (status) status.textContent = @json(__('conversations.detail.knowledge_copilot.use_stale'));
            } finally {
                if (panel.isConnected && (!staleNotice || staleNotice.hidden)) useButton.disabled = false;
            }
        });

        window.wayfindrConversationKnowledgeSuggestionTranscriptUpdated = () => {
            const panel = document.querySelector('[data-copilot-knowledge-suggestion]');

            if (!panel || panel.dataset.state !== 'ready') {
                return;
            }

            const staleNotice = panel.querySelector('[data-copilot-knowledge-suggestion-stale]');
            const useButtons = panel.querySelectorAll('[data-copilot-knowledge-use]');

            if (staleNotice) {
                staleNotice.hidden = false;
            }

            useButtons.forEach((button) => {
                button.disabled = true;
            });

            attempts = 0;
            void refresh(true);
        };

        window.setTimeout(() => refresh(), 1200);
    })();
</script>

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

            if (!panel || (!pending && !force) || (pending && attempts >= maximumAttempts)) {
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

                if (response.status === 204 || response.status === 404) {
                    panel.remove();

                    return;
                }

                if (!response.ok) {
                    throw new Error('Knowledge suggestion refresh failed.');
                }

                panel.outerHTML = await response.text();
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

                if (currentPanel?.dataset.state === 'pending') {
                    window.setTimeout(() => refresh(), 2000);
                }
            }
        };

        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const useButton = target?.closest('[data-copilot-knowledge-use]');

            if (!useButton) {
                return;
            }

            const panel = useButton.closest('[data-copilot-knowledge-suggestion]');
            const article = useButton.closest('[data-copilot-knowledge-article]');
            const replyShell = useButton.closest('[data-reply-shell]');
            const body = replyShell?.querySelector('[data-reply-body]');
            const status = panel?.querySelector('[data-copilot-knowledge-status]');
            const staleNotice = panel?.querySelector('[data-copilot-knowledge-suggestion-stale]');
            const snippet = article?.querySelector('[data-copilot-knowledge-snippet]')?.textContent ?? '';

            if (!body || !snippet.trim()) {
                return;
            }

            if (staleNotice && !staleNotice.hidden) {
                if (status) {
                    status.textContent = @json(__('conversations.detail.knowledge_copilot.use_stale'));
                }

                return;
            }

            if (body.value.trim() !== '') {
                if (status) {
                    status.textContent = @json(__('conversations.detail.knowledge_copilot.use_blocked'));
                }
                body.focus();

                return;
            }

            const templatePicker = replyShell.querySelector('[data-template-picker]');

            if (templatePicker) {
                templatePicker.value = '';
                templatePicker.dispatchEvent(new Event('change', { bubbles: true }));
            }

            body.value = snippet.trim();
            body.setAttribute('lang', '');
            body.dispatchEvent(new Event('input', { bubbles: true }));
            body.focus();

            if (status) {
                status.textContent = @json(__('conversations.detail.knowledge_copilot.used'));
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

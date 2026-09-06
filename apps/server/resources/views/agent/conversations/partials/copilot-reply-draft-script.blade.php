<script>
    (() => {
        let attempts = 0;
        let refreshInFlight = false;
        let forcedRefreshQueued = false;
        // Server-side pending freshness lasts five minutes. Six minutes of
        // polling guarantees a delayed but valid job can still replace this
        // panel with its terminal state.
        const maximumAttempts = 180;

        const refresh = async (force = false) => {
            const panel = document.querySelector('[data-copilot-reply-draft]');
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
                    throw new Error('Reply draft refresh failed.');
                }

                panel.outerHTML = await response.text();
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

                if (currentPanel?.dataset.state === 'pending') {
                    window.setTimeout(() => refresh(), 2000);
                }
            }
        };

        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const useButton = target?.closest('[data-copilot-reply-draft-use]');

            if (!useButton) {
                return;
            }

            const panel = useButton.closest('[data-copilot-reply-draft]');
            const replyShell = useButton.closest('[data-reply-shell]');
            const body = replyShell?.querySelector('[data-reply-body]');
            const status = replyShell?.querySelector('[data-reply-status]');
            const staleNotice = panel?.querySelector('[data-copilot-reply-draft-stale]');
            const draft = panel?.querySelector('[data-copilot-reply-draft-content]')?.textContent ?? '';

            if (!body || !draft.trim()) {
                return;
            }

            if (staleNotice && !staleNotice.hidden) {
                if (status) {
                    status.textContent = @json(__('conversations.detail.reply_copilot.use_stale'));
                }

                return;
            }

            if (body.value.trim() !== '') {
                if (status) {
                    status.textContent = @json(__('conversations.detail.reply_copilot.use_blocked'));
                }
                body.focus();

                return;
            }

            const templatePicker = replyShell.querySelector('[data-template-picker]');

            if (templatePicker) {
                templatePicker.value = '';
                templatePicker.dispatchEvent(new Event('change', { bubbles: true }));
            }

            body.value = draft.trim();
            body.setAttribute('lang', '');
            body.dispatchEvent(new Event('input', { bubbles: true }));
            body.focus();

            if (status) {
                status.textContent = @json(__('conversations.detail.reply_copilot.used'));
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

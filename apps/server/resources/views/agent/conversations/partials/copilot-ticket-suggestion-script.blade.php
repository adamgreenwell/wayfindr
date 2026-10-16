<script>
    (() => {
        let attempts = 0;
        let refreshInFlight = false;
        let forcedRefreshQueued = false;
        // Cover the five-minute queued freshness window plus the 85-second
        // running-job budget, with enough room for the terminal poll.
        const maximumAttempts = 210;

        const refresh = async (force = false) => {
            const panel = document.querySelector('[data-copilot-ticket-suggestion]');
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
                    throw new Error('Ticket suggestion refresh failed.');
                }

                const markup = await response.text();

                if (panel.isConnected) {
                    panel.outerHTML = markup;
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

                const currentPanel = document.querySelector('[data-copilot-ticket-suggestion]');

                if (currentPanel) {
                    // Ready output is re-authorized too, so policy edits withdraw it.
                    window.setTimeout(() => refresh(), currentPanel.dataset.state === 'pending' ? 2000 : 10000);
                }
            }
        };

        const markFormDirty = (event) => {
            const form = event.target instanceof Element
                ? event.target.closest('[data-ticket-creation-form]')
                : null;

            if (form) {
                form.dataset.ticketSuggestionDirty = 'true';
            }
        };

        document.addEventListener('input', (event) => {
            if (event.target instanceof Element && event.target.matches('[data-ticket-subject]')) {
                markFormDirty(event);
            }
        });

        document.addEventListener('change', (event) => {
            if (event.target instanceof Element && event.target.matches('[data-ticket-priority], [data-ticket-label-id]')) {
                markFormDirty(event);
            }
        });

        document.addEventListener('click', async (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const useButton = target?.closest('[data-copilot-ticket-suggestion-use]');
            if (!useButton || useButton.disabled) return;
            const panel = useButton.closest('[data-copilot-ticket-suggestion]');
            const form = document.querySelector('[data-ticket-creation-form]');
            const status = panel?.querySelector('[data-copilot-ticket-suggestion-status]');
            const staleNotice = panel?.querySelector('[data-copilot-ticket-suggestion-stale]');
            const subject = form?.querySelector('[data-ticket-subject]');
            const priority = form?.querySelector('[data-ticket-priority]');
            if (!form || !subject || !priority || !panel?.dataset.useUrl || !panel.dataset.generation) return;
            if (staleNotice && !staleNotice.hidden) {
                if (status) status.textContent = @json(__('conversations.detail.ticket_copilot.use_stale'));
                return;
            }
            if (form.dataset.ticketSuggestionDirty === 'true') {
                if (status) status.textContent = @json(__('conversations.detail.ticket_copilot.use_blocked'));
                subject.focus();
                return;
            }
            const snapshot = () => JSON.stringify([subject.value, priority.value,
                Array.from(form.querySelectorAll('[data-ticket-label-id]')).map((item) => [item.value, item.checked])]);
            const initialValues = snapshot();
            const generation = panel.dataset.generation;
            const policyRevision = panel.dataset.policyRevision;
            useButton.disabled = true;
            try {
                const query = new URLSearchParams({ generation });
                const response = await fetch(`${panel.dataset.useUrl}?${query}`, {
                    credentials: 'same-origin', cache: 'no-store',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) {
                    if ([403, 404, 410].includes(response.status)) panel.remove();
                    throw new Error('Suggestion is no longer available.');
                }
                const payload = await response.json();
                if (!panel.isConnected || document.querySelector('[data-copilot-ticket-suggestion]') !== panel
                    || panel.dataset.generation !== generation || panel.dataset.policyRevision !== policyRevision
                    || payload.generation !== generation || (payload.policy_revision ?? '') !== policyRevision
                    || (staleNotice && !staleNotice.hidden)) return;
                if (form.dataset.ticketSuggestionDirty === 'true' || snapshot() !== initialValues) {
                    if (status) status.textContent = @json(__('conversations.detail.ticket_copilot.use_blocked'));
                    subject.focus();
                    return;
                }
                if (typeof payload.title !== 'string' || !payload.title.trim()
                    || typeof payload.priority !== 'string' || !Array.isArray(payload.label_ids)) {
                    throw new Error('Suggestion is no longer available.');
                }
                const suggestedLabelIds = new Set(payload.label_ids.map(String));
                subject.value = payload.title;
                subject.setAttribute('lang', '');
                priority.value = payload.priority;
                form.querySelectorAll('[data-ticket-label-id]').forEach((checkbox) => {
                    checkbox.checked = suggestedLabelIds.has(checkbox.value);
                });
                form.dataset.ticketSuggestionDirty = 'true';
                subject.dispatchEvent(new Event('input', { bubbles: true }));
                priority.dispatchEvent(new Event('change', { bubbles: true }));
                subject.focus();
                if (status) status.textContent = @json(__('conversations.detail.ticket_copilot.used'));
            } catch (error) {
                if (staleNotice) staleNotice.hidden = false;
                if (status) status.textContent = @json(__('conversations.detail.ticket_copilot.use_stale'));
            } finally {
                if (panel.isConnected && (!staleNotice || staleNotice.hidden)) useButton.disabled = false;
            }
        });

        window.wayfindrConversationTicketSuggestionTranscriptUpdated = () => {
            const panel = document.querySelector('[data-copilot-ticket-suggestion]');

            if (!panel || panel.dataset.state !== 'ready') {
                return;
            }

            const staleNotice = panel.querySelector('[data-copilot-ticket-suggestion-stale]');
            const useButton = panel.querySelector('[data-copilot-ticket-suggestion-use]');

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

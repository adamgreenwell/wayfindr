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
                    throw new Error('Ticket suggestion refresh failed.');
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

                const currentPanel = document.querySelector('[data-copilot-ticket-suggestion]');

                if (currentPanel?.dataset.state === 'pending') {
                    window.setTimeout(() => refresh(), 2000);
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

        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const useButton = target?.closest('[data-copilot-ticket-suggestion-use]');

            if (!useButton) {
                return;
            }

            const panel = useButton.closest('[data-copilot-ticket-suggestion]');
            const form = document.querySelector('[data-ticket-creation-form]');
            const status = panel?.querySelector('[data-copilot-ticket-suggestion-status]');
            const staleNotice = panel?.querySelector('[data-copilot-ticket-suggestion-stale]');
            const subject = form?.querySelector('[data-ticket-subject]');
            const priority = form?.querySelector('[data-ticket-priority]');
            const title = panel?.querySelector('[data-copilot-ticket-title]')?.textContent?.trim() ?? '';
            const priorityValue = panel?.querySelector('[data-copilot-ticket-priority]')?.dataset.value ?? '';

            if (!form || !subject || !priority || !title || !priorityValue) {
                return;
            }

            if (staleNotice && !staleNotice.hidden) {
                if (status) {
                    status.textContent = @json(__('conversations.detail.ticket_copilot.use_stale'));
                }

                return;
            }

            if (form.dataset.ticketSuggestionDirty === 'true') {
                if (status) {
                    status.textContent = @json(__('conversations.detail.ticket_copilot.use_blocked'));
                }
                subject.focus();

                return;
            }

            const suggestedLabelIds = new Set(
                Array.from(panel.querySelectorAll('[data-copilot-ticket-label-id]'))
                    .map((label) => label.dataset.copilotTicketLabelId)
            );

            subject.value = title;
            subject.setAttribute('lang', '');
            priority.value = priorityValue;

            form.querySelectorAll('[data-ticket-label-id]').forEach((checkbox) => {
                checkbox.checked = suggestedLabelIds.has(checkbox.value);
            });

            form.dataset.ticketSuggestionDirty = 'true';
            subject.dispatchEvent(new Event('input', { bubbles: true }));
            priority.dispatchEvent(new Event('change', { bubbles: true }));
            subject.focus();

            if (status) {
                status.textContent = @json(__('conversations.detail.ticket_copilot.used'));
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

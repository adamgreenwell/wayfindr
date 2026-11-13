<script>
(() => {
    document.querySelectorAll('[data-assist-rail]').forEach(rail => {
        if (rail.dataset.initialized === 'true') return;
        rail.dataset.initialized = 'true';
        rail.querySelectorAll('[data-assist-section]').forEach(section => {
            const resting = section.querySelector('[data-assist-resting]');
            if (!resting) return;
            const update = () => {
                resting.hidden = Array.from(section.children).some(child => child.matches(
                    '[data-ai-assist], [data-copilot-summary], [data-copilot-reply-draft], [data-copilot-knowledge-suggestion]'
                ));
            };
            update();
            // Observe only card replacement/removal, not generated output edits.
            new MutationObserver(update).observe(section, {childList:true});
        });
        const aside = rail.closest('aside');
        let copy = {};
        try { copy = JSON.parse(aside?.dataset.assistCopy || '{}'); } catch (error) { /* Plain task labels remain readable. */ }
        const live = aside?.querySelector('[data-assist-live]');
        const sections = Array.from(rail.querySelectorAll('[data-assist-section]'));
        const seen = new Map();
        const announce = message => {
            if (live && document.visibilityState === 'visible' && !rail.closest('[hidden]') && live.textContent !== message) live.textContent = message;
        };
        const describe = section => {
            const card = Array.from(section.children).find(child => child.matches('[data-ai-assist], [data-copilot-summary], [data-copilot-reply-draft], [data-copilot-knowledge-suggestion]'));
            const task = section.dataset.assistSection;
            let state = card?.dataset.state || section.dataset.assistRestingState || 'unavailable';
            if (state === 'pending' || state === 'running') state = 'preparing';
            if (state === 'failed') state = 'unavailable';
            if (card?.querySelector('[data-copilot-summary-stale]:not([hidden]), [data-copilot-reply-draft-stale]:not([hidden]), [data-copilot-knowledge-suggestion-stale]:not([hidden])')) state = 'stale';
            const ordinal = Number(card?.dataset.sourceOrdinal), count = Number(card?.dataset.sourceMessageCount);
            const position = Number.isInteger(ordinal) && ordinal > 0 && Number.isInteger(count) && count >= 0
                ? (copy.position || '').replace(':position', new Intl.NumberFormat(document.documentElement.lang || 'en').format(ordinal)).replace(':count', new Intl.NumberFormat(document.documentElement.lang || 'en').format(count)) : '';
            let url = null;
            try { const candidate = new URL(card?.dataset.sourceUrl || '', window.location.href); if (position && candidate.origin === window.location.origin && candidate.hash.startsWith('#conversation-message-')) url = candidate.hash; } catch (error) { /* No unvalidated source navigation. */ }
            return {task,state,position,url,generation:card?.dataset.generation || ''};
        };
        const update = (notify = true) => {
            const messages = [];
            sections.forEach(section => {
                const current = describe(section), label = copy.tasks?.[current.task] || current.task;
                const text = copy.states?.[current.state] || copy.states?.unavailable || current.state;
                const status = rail.querySelector(`[data-assist-freshness-task="${current.task}"]`);
                const link = rail.querySelector(`[data-assist-position-task="${current.task}"]`);
                const description = current.position ? `${text} ${current.position}` : `${text} ${copy.no_position || ''}`;
                const statusText = current.position ? text : description;
                if (status && status.textContent !== statusText) status.textContent = statusText;
                if (link) {
                    if (current.url) { if (link.getAttribute('href') !== current.url) link.setAttribute('href', current.url); if (link.textContent !== current.position) link.textContent = current.position; link.hidden = false; }
                    else { link.hidden = true; link.removeAttribute('href'); link.textContent = ''; }
                }
                const signature = JSON.stringify([current.state,current.position,current.generation]);
                if (notify && seen.has(current.task) && seen.get(current.task) !== signature) messages.push(`${label}: ${description}`);
                seen.set(current.task, signature);
            });
            if (messages.length) announce(messages.join(' '));
        };
        let scheduled = false;
        const schedule = () => {
            if (scheduled) return;
            scheduled = true;
            Promise.resolve().then(() => { scheduled = false; update(); });
        };
        sections.forEach(section => new MutationObserver(schedule).observe(section, {subtree:true,childList:true,attributes:true,
            attributeFilter:['data-state','data-generation','data-source-position','data-source-ordinal','data-source-message-count','data-source-url','hidden']}));
        document.addEventListener('wayfindr:assist-task-updated', schedule);
        window.addEventListener('wayfindr:assist-action', event => { if (typeof event.detail?.message === 'string') announce(event.detail.message); });
        update(false);
        const key = rail.dataset.preferenceKey;
        let saved = null;
        try { saved = window.localStorage.getItem(key); } catch (error) { /* Browser storage is optional. */ }
        rail.open = saved === 'open' || (saved !== 'closed' && window.matchMedia('(min-width: 1101px)').matches);
        let lastOpen = rail.open;
        rail.addEventListener('toggle', () => {
            if (rail.open === lastOpen) return;
            lastOpen = rail.open;
            try { window.localStorage.setItem(key, rail.open ? 'open' : 'closed'); } catch (error) { /* Native disclosure stays usable. */ }
        });
    });
})();
</script>

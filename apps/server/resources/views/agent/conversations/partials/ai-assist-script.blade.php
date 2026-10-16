<script>
(() => {
    const states = new WeakMap();
    const replyEdits = new WeakMap();
    let sourceVersion = 0;
    const panels = () => Array.from(document.querySelectorAll('[data-ai-assist]'));
    const visible = panel => document.visibilityState === 'visible' && document.prerendering !== true && panel.isConnected
        && !panel.closest('[hidden]') && !!panel.closest('[data-tab-panel]');
    const data = panel => states.get(panel);
    const setMessage = (panel, message) => { panel.querySelector('[data-ai-message]').textContent = message; };
    const initialTasks = () => panels().filter(panel => panel.dataset.mode === 'manual' && panel.dataset.workspace === 'conversation'
        && visible(panel) && data(panel)?.value?.generation === null && data(panel)?.value?.can_prepare);
    const updateGroups = () => document.querySelectorAll('[data-ai-prepare-all]').forEach(button => {
        button.disabled = !initialTasks().some(panel => !data(panel).inFlight);
    });
    const markDirty = event => {
        if (!(event.target instanceof Element)) return;
        const form = event.target.closest('[data-ticket-creation-form]');
        if (form) form.dataset.ticketSuggestionDirty = 'true';
        if (event.target.matches('[data-reply-body]')) replyEdits.set(event.target, (replyEdits.get(event.target) || 0) + 1);
    };
    document.addEventListener('input', markDirty);
    document.addEventListener('change', markDirty);

    const useOutput = async (panel, articleId = null) => {
        const entry = data(panel);
        const state = entry?.value;
        if (!state?.can_use || state.state !== 'ready' || entry.using || !visible(panel)) return;
        const generation = state.generation;
        const policy = state.policy_revision;
        const version = sourceVersion;
        const form = document.querySelector('[data-ticket-creation-form]');
        const body = panel.closest('[data-reply-shell]')?.querySelector('[data-reply-body]');
        const ticket = panel.dataset.task === 'ticket';
        const snapshot = () => ticket ? JSON.stringify(Array.from(form?.elements || []).map(field => [field.name, field.value, field.checked])) : body?.value;
        const dirty = () => ticket ? !form || form.dataset.ticketSuggestionDirty === 'true' : !body || body.value.trim() !== '';
        if (dirty()) { setMessage(panel, entry.copy.blocked); return; }
        const initial = snapshot();
        const edits = body ? replyEdits.get(body) || 0 : 0;
        entry.using = true;
        try {
            const response = await fetch(`${panel.dataset.useUrl}?${new URLSearchParams({generation})}`, {
                credentials: 'same-origin', cache: 'no-store', headers: {'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
            });
            if (!response.ok) throw new Error('Unavailable');
            const current = await response.json();
            if (!visible(panel) || sourceVersion !== version || entry.value.generation !== generation || entry.value.policy_revision !== policy || !entry.value.can_use
                || current.generation !== generation || current.policy_revision !== policy || !current.can_use
                || dirty() || snapshot() !== initial || (body && (replyEdits.get(body) || 0) !== edits)) return;
            if (ticket) {
                const output = current.output;
                const title = form.querySelector('[data-ticket-subject]');
                const priority = form.querySelector('[data-ticket-priority]');
                if (!title || !priority || typeof output?.title !== 'string' || !Array.isArray(output.label_ids)) return;
                const labels = new Set(output.label_ids.map(String));
                title.value = output.title; title.setAttribute('lang', ''); priority.value = output.priority;
                form.querySelectorAll('[data-ticket-label-id]').forEach(field => { field.checked = labels.has(field.value); });
                form.dataset.ticketSuggestionDirty = 'true';
                title.dispatchEvent(new Event('input', {bubbles:true}));
                priority.dispatchEvent(new Event('change', {bubbles:true}));
            } else {
                const text = articleId === null ? current.output?.text : current.output?.articles?.find(article => article.id === articleId)?.snippet;
                if (typeof text !== 'string' || !text.trim()) return;
                const picker = panel.closest('[data-reply-shell]')?.querySelector('[data-template-picker]');
                if (picker) { picker.value = ''; picker.dispatchEvent(new Event('change', {bubbles:true})); }
                body.value = text.trim(); body.setAttribute('lang', '');
                body.dispatchEvent(new Event('input', {bubbles:true})); body.focus();
            }
            setMessage(panel, entry.copy.used);
        } catch (error) {
            entry.value = {...state, state:'stale', can_use:false}; render(panel);
            void read(panel, true);
        } finally { entry.using = false; }
    };

    const render = panel => {
        const entry = data(panel), state = entry.value, copy = entry.copy;
        panel.dataset.state = state.state;
        const signature = JSON.stringify([state.state, state.output, state.can_use, state.generation, state.policy_revision, state.source_message_count]);
        if (entry.renderedSignature !== signature) {
            entry.renderedSignature = signature;
            setMessage(panel, copy.states[state.state] || copy.states.unavailable);
            const output = panel.querySelector('[data-ai-output]');
            output.replaceChildren();
            const text = value => { const p = document.createElement('p'); p.textContent = value; output.append(p); };
            const button = (label, articleId = null) => {
                const element = document.createElement('button'); element.type = 'button'; element.className = 'button secondary';
                element.textContent = label; element.disabled = !state.can_use; element.dataset.aiUse = '';
                element.addEventListener('click', () => void useOutput(panel, articleId)); output.append(element);
            };
            if (state.output) {
                if (typeof state.output.text === 'string') {
                    text(state.output.text);
                    if (panel.dataset.task === 'reply') button(copy.use);
                } else if (Array.isArray(state.output.articles)) {
                    if (state.output.articles.length === 0) text(copy.no_matches);
                state.output.articles.forEach(article => { text(article.title); text(article.snippet); button(copy.use_article, article.id); });
                } else if (typeof state.output.title === 'string') { text(state.output.title); text(copy.priorities[state.output.priority] || state.output.priority); button(copy.apply); }
            }
        }
        const source = panel.querySelector('[data-ai-source]');
        if (source) {
            const count = state.source_message_count;
            const message = Number.isInteger(count) ? (count === 1 ? copy.source_one : copy.source_many).replace(':count', String(count)) : '';
            if (source.textContent !== message) source.textContent = message;
        }
        const prepare = panel.querySelector('[data-ai-prepare]');
        prepare.value = state.generation ? 'refresh' : 'initial';
        prepare.textContent = !state.generation ? (panel.dataset.task === 'ticket' ? copy.prepare_ticket : copy.prepare) : state.state === 'unavailable' ? copy.retry : copy.refresh;
        prepare.disabled = !state.can_prepare || entry.inFlight;
        prepare.hidden = panel.dataset.mode === 'off';
        const seconds = Math.max(0, Math.ceil((entry.retryUntil - Date.now()) / 1000));
        panel.querySelector('[data-ai-cooldown]').textContent = seconds ? copy.cooldown.replace(':seconds', String(seconds)) : '';
        updateGroups();

    };
    const accept = (panel, value) => {
        const entry = data(panel);
        if (value.generation !== entry.value.generation) entry.polls = 0;
        if (['prepare', 'manual', 'off'].includes(value.mode)) panel.dataset.mode = value.mode;
        entry.value = value;
        entry.retryUntil = Date.now() + (value.retry_after || 0) * 1000;
        entry.nextRead = Date.now() + (value.state === 'preparing' ? 2000 : 10000);
        render(panel);
    };
    const request = async (panel, action = null) => {
        const entry = data(panel);
        if (!entry || entry.inFlight || !visible(panel)) {
            if (entry) {
                if (entry.inFlight && action === 'open' && visible(panel)) entry.openQueued = true;
                else entry.readQueued = true;
            }
            return;
        }
        const version = sourceVersion;
        entry.inFlight = true;
        try {
            const headers = {'Accept':'application/json','X-Requested-With':'XMLHttpRequest'};
            const options = {credentials:'same-origin', cache:'no-store', headers};
            if (action) {
                options.method = 'POST'; headers['Content-Type'] = 'application/json';
                headers['X-CSRF-TOKEN'] = panel.querySelector('input[name="_token"]').value;
                options.body = JSON.stringify({action, workspace:panel.dataset.workspace});
            }
            const response = await fetch(action ? panel.dataset.ensureUrl : panel.dataset.statusUrl, options);
            const value = await response.json().catch(() => null);
            if ([403,404,410].includes(response.status)) panel.dataset.mode = 'off';
            if (sourceVersion !== version || !panel.isConnected) { entry.readQueued = true; return; }
            if (!response.ok || !value) {
                accept(panel, {state:'unavailable', output:null, can_use:false, can_prepare:false,
                    generation:entry.value.generation, retry_after:Number(response.headers.get('Retry-After')) || 0});
                return;
            }
            accept(panel, value);
        } catch (error) {
            if (panel.isConnected) accept(panel, {...entry.value, state:'unavailable', output:null, can_use:false, can_prepare:false});
        } finally {
            entry.inFlight = false;
            if (panel.isConnected) render(panel);
            if (entry.readQueued) { entry.readQueued = false; entry.nextRead = 0; }
            const reopen = entry.openQueued; entry.openQueued = false;
            if (reopen && visible(panel) && panel.dataset.mode === 'prepare') void request(panel, 'open');
        }
    };
    const read = (panel, force = false) => {
        const entry = data(panel);
        if (!entry || !visible(panel) || (!force && entry.nextRead > Date.now())) return;
        if (entry.value.state === 'preparing' && entry.polls++ >= 210) return;
        return request(panel);
    };
    const checkOpens = () => {
        panels().forEach(panel => {
            if (!data(panel)) {
                try {
                    states.set(panel, {value:JSON.parse(panel.dataset.initial), copy:JSON.parse(panel.dataset.copy),
                        nextRead:0, retryUntil:0, polls:0, wasVisible:false, inFlight:false, readQueued:false, openQueued:false, using:false});
                    const entry = data(panel); entry.retryUntil = Date.now() + (entry.value.retry_after || 0) * 1000;
                    render(panel);
                    panel.querySelector('[data-ai-form]').addEventListener('submit', event => {
                        event.preventDefault(); const button = panel.querySelector('[data-ai-prepare]');
                        if (!button.disabled) void request(panel, button.value);
                    });
                } catch (error) { return; }
            }
            const entry = data(panel), nowVisible = visible(panel);
            if (nowVisible && !entry.wasVisible) {
                entry.polls = 0; entry.nextRead = 0;
                if (panel.dataset.mode === 'prepare') void request(panel, 'open');
                else void read(panel, true);
            }
            entry.wasVisible = nowVisible;
        });
    };
    document.addEventListener('click', event => {
        const button = event.target instanceof Element ? event.target.closest('[data-ai-prepare-all]') : null;
        if (button && !button.disabled) initialTasks().forEach(panel => void request(panel, 'initial'));
    });
    window.addEventListener('wayfindr:assist-source-changed', () => {
        sourceVersion++;
        panels().forEach(panel => {
            const entry = data(panel); if (!entry) return;
            entry.value = {...entry.value, state:'stale', can_use:false}; render(panel);
            void read(panel, true);
        });
    });
    window.addEventListener('wayfindr:tab-shown', checkOpens);
    window.addEventListener('pageshow', checkOpens);
    window.addEventListener('pagehide', () => panels().forEach(panel => { if (data(panel)) data(panel).wasVisible = false; }));
    document.addEventListener('visibilitychange', checkOpens);
    document.addEventListener('prerenderingchange', checkOpens);
    checkOpens();
    window.setInterval(() => panels().forEach(panel => {
        if (data(panel) && visible(panel)) { render(panel); void read(panel); }
    }), 2000);
})();
</script>

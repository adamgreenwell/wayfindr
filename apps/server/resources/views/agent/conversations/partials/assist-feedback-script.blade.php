<script>
(() => {
    if (window.wayfindrAssistFeedback) return;
    const records = new WeakMap(), mounted = new Set();
    const visible = element => element?.isConnected && document.visibilityState === 'visible' && document.prerendering !== true
        && !element.closest('[hidden]') && !Array.from(element.closest('[data-reply-shell], [data-tab-panel]')?.querySelectorAll('details:not([open])') || []).some(details => details.contains(element))
        && element.getClientRects().length > 0 && (() => {
            const rect = element.getBoundingClientRect();
            return rect.bottom > 0 && rect.top < window.innerHeight && rect.right > 0 && rect.left < window.innerWidth;
        })();
    const region = panel => panel?.querySelector('[data-assist-feedback]');
    const mount = panel => {
        const root = region(panel); if (!root) return null;
        if (records.has(root)) return records.get(root);
        let copy = {}; try { copy = JSON.parse(root.dataset.feedbackCopy); } catch (_) {}
        const record = {root, panel, copy, generation:null, revision:0, used:false, viewed:false, queue:Promise.resolve()};
        records.set(root, record); mounted.add(record);
        const status = message => {
            root.querySelector('[data-feedback-status]').textContent = message;
            if (message) {
                if (panel.dataset.task === 'ticket') panel.querySelector('[data-ai-message]').textContent = message;
                else window.dispatchEvent(new CustomEvent('wayfindr:assist-action', {detail:{message}}));
            }
        };
        record.status = status;
        root.querySelector('[data-feedback-reject]')?.addEventListener('click', () => {
            if (visible(root) && record.generation) send(record, {event:'rejected'});
        });
        root.querySelector('[data-feedback-save]')?.addEventListener('click', () => {
            if (!visible(root) || !record.generation) return;
            const usefulness = root.querySelector('[data-feedback-usefulness]').value;
            if (!usefulness) { status(copy.choose); return; }
            send(record, {event:'assessment', usefulness, correction:record.used ? root.querySelector('[data-feedback-correction]').value : 'unassessed'});
        });
        return record;
    };
    const send = (record, input) => {
        const generation = record.generation;
        record.queue = record.queue.then(async () => {
            let retryable = true, timeout = null;
            try {
                let signal;
                if (input.event === 'used') {
                    const pending = record.pendingUse;
                    if (!pending || pending.generation !== generation || pending.receipt !== input.receipt) return;
                    if (performance.now() >= pending.deadline) { discardUse(record); return; }
                    pending.controller = new AbortController(); signal = pending.controller.signal;
                    timeout = window.setTimeout(() => pending.controller.abort(), pending.deadline - performance.now());
                }
                const payload = {...input, generation};
                if (input.event === 'assessment') payload.expected_revision = record.revision;
                const response = await fetch(record.root.dataset.feedbackUrl, {method:'POST', credentials:'same-origin', cache:'no-store',
                    headers:{'Accept':'application/json','Content-Type':'application/json','X-Requested-With':'XMLHttpRequest',
                        'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || record.panel.closest('form')?.querySelector('[name="_token"]')?.value || document.querySelector('input[name="_token"]')?.value || ''},
                    body:JSON.stringify(payload), signal});
                if (!response.ok) {
                    retryable = response.status === 429 || response.status >= 500;
                    throw new Error('unavailable');
                }
                const value = await response.json();
                if (record.generation !== generation) return;
                if (input.event === 'used' && record.pendingUse?.receipt === input.receipt) { discardUse(record); record.status(''); }
                if (input.event === 'viewed') {
                    record.root.querySelector('[data-feedback-usefulness]').value = value.usefulness || '';
                    record.root.querySelector('[data-feedback-correction]').value = value.correction || 'unassessed';
                    record.status('');
                }
                record.revision = value.revision; record.used = value.used; record.viewed = value.viewed;
                record.root.querySelector('[data-feedback-correction-label]').hidden = !value.used;
                record.root.querySelector('[data-feedback-reject]').disabled = value.rejected;
                if (input.event === 'assessment' || input.event === 'rejected') record.status(record.copy.saved);
            } catch (_) {
                if (record.generation === generation) {
                    if (input.event === 'viewed' && record.viewAttempt === generation) record.viewAttempt = null;
                    if (input.event === 'used' && record.pendingUse?.receipt === input.receipt) {
                        if (retryable && performance.now() < record.pendingUse.deadline) {
                            record.pendingUse.attempting = false; record.pendingUse.nextAttempt = performance.now() + 2000;
                        } else discardUse(record);
                    }
                    record.status(record.copy.unavailable);
                }
            } finally { if (timeout !== null) window.clearTimeout(timeout); }
        });
    };
    const discardUse = record => { record.pendingUse?.controller?.abort(); record.pendingUse = null; };
    const identify = (record, generation) => {
        if (record.generation === generation) return;
        record.generation = generation; record.revision = 0; record.used = false; record.viewed = false;
        discardUse(record);
        record.root.querySelector('[data-feedback-controls]').hidden = false;
        record.root.querySelector('[data-feedback-correction-label]').hidden = true;
        record.root.querySelector('[data-feedback-reject]').disabled = false;
        record.root.querySelector('[data-feedback-usefulness]').value = '';
        record.root.querySelector('[data-feedback-correction]').value = 'unassessed'; record.status('');
    };
    const viewed = (panel, generation, target = panel) => {
        const record = mount(panel); if (!record || !generation) return;
        record.pendingView = {generation, target};
        if (!visible(target) || !target.textContent.trim()) return;
        identify(record, generation);
        record.root.querySelector('[data-feedback-controls]').hidden = false;
        if (record.viewAttempt === generation) return;
        record.viewAttempt = generation; send(record, {event:'viewed'});
    };
    const retryUse = record => {
        const pending = record.pendingUse; if (!pending) return;
        if (pending.generation !== record.generation || performance.now() >= pending.deadline) { discardUse(record); return; }
        if (pending.attempting || performance.now() < pending.nextAttempt || document.visibilityState !== 'visible' || document.prerendering === true) return;
        pending.attempting = true; send(record, {event:'used', receipt:pending.receipt});
    };
    const used = (panel, generation, receipt) => {
        const record = mount(panel); if (!record || typeof receipt !== 'string' || !generation) return;
        identify(record, generation); if (record.used) return;
        if (!record.pendingUse || record.pendingUse.receipt !== receipt) {
            record.pendingUse = {generation, receipt, deadline:performance.now() + 60000, nextAttempt:performance.now(), attempting:false};
        }
        retryUse(record);
    };
    const scan = () => { document.querySelectorAll('[data-ai-assist]').forEach(panel => {
        let state; try { state = JSON.parse(panel.dataset.initial); } catch (_) {}
        const generation = panel.dataset.generation || state?.generation;
        const output = panel.querySelector('[data-ai-output]');
        if (panel.dataset.state === 'ready' && output?.textContent.trim() && (panel.dataset.task !== 'knowledge' || output.querySelector('[data-ai-use]'))) {
            if (visible(output)) viewed(panel, generation, output);
            else { const record = mount(panel); if (record) record.root.querySelector('[data-feedback-controls]').hidden = record.generation !== generation; }
        } else { const record = mount(panel); if (record) record.root.querySelector('[data-feedback-controls]').hidden = true; }
    });
        mounted.forEach(record => {
            if (!record.root.isConnected) { discardUse(record); mounted.delete(record); return; }
            retryUse(record);
            if (record.panel.matches('[data-draft-refinement]') && record.pendingView) {
                const {generation, target} = record.pendingView; viewed(record.panel, generation, target);
            }
        });
    };
    const end = panel => { const record = mount(panel); if (record) { record.pendingView = null; record.root.querySelector('[data-feedback-controls]').hidden = true; } };
    window.wayfindrAssistFeedback = {viewed, used, end};
    document.addEventListener('wayfindr:assist-task-updated', scan);
    document.addEventListener('visibilitychange', scan);
    document.addEventListener('toggle', scan, true);
    window.addEventListener('wayfindr:tab-shown', scan);
    window.addEventListener('scroll', scan, {passive:true});
    window.addEventListener('resize', scan);
    window.addEventListener('pagehide', () => mounted.forEach(discardUse));
    window.setInterval(scan, 2000); scan();
})();
</script>

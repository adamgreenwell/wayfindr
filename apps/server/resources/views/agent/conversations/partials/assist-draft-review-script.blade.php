<script>
(() => {
    if (window.wayfindrAssistDraftReview) return;
    const records = new WeakMap(), mounted = new Set();
    let sourceRevision = 0;
    const text = (element, value) => { if (element && element.textContent !== value) element.textContent = value; };
    const identity = value => value && JSON.stringify([
        value.generation || '', value.policyRevision || '', String(value.articleId || ''), value.articleRevision || '',
        String(value.sourcePosition ?? ''), value.outputGeneration || value.generation || ''
    ]);
    const current = active => { try { return active.adapter.readCurrent(); } catch (error) { return null; } };
    const eligible = value => value?.canUse === true && typeof value.generation === 'string' && value.generation !== '';
    const writable = record => record.shell.isConnected && record.body.isConnected
        && record.shell.querySelector('[data-reply-body]') === record.body && !record.body.readOnly && !record.body.disabled
        && record.body.closest('form')?.dataset.submitting !== 'true' && record.body.closest('form')?.getAttribute('aria-busy') !== 'true'
        && !record.shell.closest('[hidden]') && document.visibilityState === 'visible' && document.prerendering !== true;
    const announce = message => { if (message) window.dispatchEvent(new CustomEvent('wayfindr:assist-action', {detail:{message}})); };
    const controls = record => {
        if (record.apply) record.apply.disabled = !record.active || record.active.invalid || record.active.busy || record.active.stage !== 'preview';
    };
    const invalidate = (record, withdraw = true, message = record.copy.invalid || '') => {
        const active = record.active;
        if (!active) return;
        const changed = !active.invalid || active.message !== message;
        active.invalid = true; active.message = message;
        if (withdraw) { active.candidate = null; text(record.candidate, ''); text(record.original, ''); record.sources?.replaceChildren(); if (record.sources) record.sources.hidden = true; }
        text(record.status, message); controls(record);
        if (changed) {
            announce(message);
            active.adapter.onInvalidated?.();
        }
    };
    const close = (record, reason = 'cancelled') => {
        const previous = record.active;
        record.sequence++;
        record.active = null;
        text(record.candidate, ''); text(record.original, ''); text(record.status, '');
        record.sources?.replaceChildren(); if (record.sources) record.sources.hidden = true;
        if (record.region) record.region.hidden = true;
        controls(record);
        previous?.adapter.onClosed?.(reason);
    };
    const authorityCurrent = (record, active) => {
        const value = current(active);
        return sourceRevision === active.sourceRevision && eligible(value) && identity(value) === active.identity;
    };
    const draftCurrent = (record, active) => writable(record) && record.revision === active.revision && record.body.value === active.original;
    const valid = (record, active) => record.active === active && !active.invalid && authorityCurrent(record, active) && draftCurrent(record, active);
    const candidateText = (active, value) => active.adapter.preserveWhitespace ? value : value.trim();
    const authorized = (active, value) => eligible(value) && identity(value) === active.identity && typeof value.text === 'string' && value.text.trim() !== '';
    const commit = (record, active) => {
        if (!valid(record, active)) return;
        const candidate = active.candidate;
        const picker = record.shell.querySelector('[data-template-picker]');
        // The last comparison and assignment are adjacent: callbacks may make later edits.
        record.body.value = candidate;
        record.body.setAttribute('lang', '');
        if (picker) picker.value = '';
        close(record, 'applied');
        if (picker) picker.dispatchEvent(new Event('change', {bubbles:true}));
        record.body.dispatchEvent(new Event('input', {bubbles:true}));
        record.body.focus();
        announce(record.copy.used);
        active.adapter.onApplied?.(active.feedbackReceipt);
    };
    const apply = async record => {
        const active = record.active;
        if (!active || active.busy || active.stage !== 'preview' || active.invalid) return;
        if (!authorityCurrent(record, active)) { invalidate(record); return; }
        if (!draftCurrent(record, active)) { invalidate(record, false); return; }
        active.busy = true; controls(record);
        try {
            const value = await active.adapter.fetchAuthorized('apply');
            if (record.active !== active) return;
            if (!authorityCurrent(record, active)) { invalidate(record); return; }
            if (!draftCurrent(record, active)) { invalidate(record, false); return; }
            if (!authorized(active, value) || candidateText(active, value.text) !== active.candidate) { invalidate(record); return; }
            active.feedbackReceipt = value.feedbackReceipt;
            commit(record, active);
        } catch (error) {
            if (record.active === active) {
                invalidate(record, true, record.copy.unavailable || '');
                active.adapter.onUnavailable?.();
            }
        } finally { active.busy = false; controls(record); }
    };
    const mount = shell => {
        if (records.has(shell)) return records.get(shell);
        const body = shell?.querySelector('[data-reply-body]');
        if (!body) return null;
        const region = shell.querySelector('[data-assist-draft-review]');
        let copy = {};
        try { copy = JSON.parse(region?.dataset.copy || '{}'); } catch (error) { /* Missing disclosure never permits a replacement. */ }
        const record = {shell, body, region, copy, revision:0, sequence:0, active:null,
            heading:region?.querySelector('[data-assist-review-heading]'),
            original:region?.querySelector('[data-assist-review-original]'), candidate:region?.querySelector('[data-assist-review-candidate]'),
            status:region?.querySelector('[data-assist-review-status]'), sources:region?.querySelector('[data-assist-review-sources]'), apply:region?.querySelector('[data-assist-review-apply]')};
        records.set(shell, record); mounted.add(record);
        record.apply?.addEventListener('click', () => void apply(record));
        region?.querySelector('[data-assist-review-cancel]')?.addEventListener('click', () => {
            const button = record.active && current(record.active)?.button;
            close(record); announce(record.copy.cancelled);
            const target = button?.isConnected && !button.disabled ? button : shell.querySelector('[data-assist-rail] > summary');
            (target || body).focus();
        });
        const watchWritable = new MutationObserver(() => { if (record.active && !writable(record)) invalidate(record); });
        watchWritable.observe(body, {attributes:true, attributeFilter:['readonly','disabled']});
        if (body.closest('form')) watchWritable.observe(body.closest('form'), {attributes:true, attributeFilter:['data-submitting','aria-busy']});
        shell.querySelectorAll('[data-assist-section]').forEach(section => new MutationObserver(() => sync(section.dataset.assistSection)).observe(section, {
            childList:true, subtree:true, attributes:true,
            attributeFilter:['data-state','data-generation','data-policy-revision','data-article-revision','data-can-use','hidden']
        }));
        return record;
    };
    const sync = task => mounted.forEach(record => {
        const active = record.active;
        if (active && active.adapter.task === task && !authorityCurrent(record, active)) invalidate(record);
    });
    const offer = async adapter => {
        const record = mount(adapter.shell);
        if (!record || !writable(record)) return;
        const value = (() => { try { return adapter.readCurrent(); } catch (error) { return null; } })();
        if (!eligible(value)) return;
        if (record.active?.busy && !record.active.invalid && record.active.identity === identity(value) && record.active.adapter.task === adapter.task) return;
        close(record);
        const active = {adapter, identity:identity(value), sourceRevision, original:record.body.value, revision:record.revision,
            sequence:record.sequence, candidate:null, stage:'loading', busy:true, invalid:false};
        record.active = active;
        const preview = adapter.forcePreview === true || active.original !== '';
        if (preview && (!record.region || !record.original || !record.candidate || !record.apply)) {
            close(record); adapter.onUnavailable?.(); return;
        }
        if (preview) { record.region.hidden = false; text(record.original, active.original); }
        controls(record);
        try {
            const fresh = await adapter.fetchAuthorized('preview');
            if (record.active !== active) return;
            if (!authorityCurrent(record, active)) { invalidate(record); return; }
            if (!draftCurrent(record, active)) { invalidate(record, false); return; }
            if (!authorized(active, fresh)) { invalidate(record); return; }
            active.candidate = candidateText(active, fresh.text); active.feedbackReceipt = fresh.feedbackReceipt;
            if (!preview) { commit(record, active); return; }
            active.stage = 'preview'; active.adapter.onPreview?.(fresh);
            if (record.active !== active) return;
            text(record.candidate, active.candidate); text(record.status, record.copy.previewed || '');
            announce(record.copy.previewed);
            if (adapter.forcePreview) {
                const rail = record.shell.querySelector('[data-assist-rail]');
                if (rail) rail.open = true;
            }
            (record.heading || record.apply).focus();
            if (adapter.forcePreview) record.region.scrollIntoView?.({block:'nearest'});
            active.adapter.onDisplayed?.();
        } catch (error) {
            if (record.active === active) { invalidate(record, true, record.copy.unavailable || ''); adapter.onUnavailable?.(); }
        } finally { active.busy = false; controls(record); }
    };
    document.addEventListener('input', event => {
        if (!(event.target instanceof Element)) return;
        const shell = event.target.closest('[data-reply-shell]'), record = shell && records.get(shell);
        if (record && event.target === record.body) { record.revision++; invalidate(record, false); }
    });
    document.addEventListener('change', event => {
        if (!(event.target instanceof Element)) return;
        const shell = event.target.closest('[data-reply-shell]'), record = shell && records.get(shell);
        if (record && (event.target === record.body || event.target.matches('[data-template-picker]'))) { record.revision++; invalidate(record, false); }
    });
    document.addEventListener('submit', event => mounted.forEach(record => {
        if (event.target instanceof Element && event.target.contains(record.body)) invalidate(record);
    }));
    window.addEventListener('wayfindr:assist-source-changed', () => { sourceRevision++; mounted.forEach(record => invalidate(record)); });
    document.addEventListener('wayfindr:assist-task-updated', event => sync(event.detail?.task));
    new MutationObserver(() => mounted.forEach(record => {
        if (!record.shell.isConnected) { close(record, 'workspace-ended'); mounted.delete(record); }
        else if (record.active && record.shell.closest('[hidden]')) close(record, 'workspace-ended');
    })).observe(document.body, {childList:true, subtree:true, attributes:true, attributeFilter:['hidden']});
    window.wayfindrAssistDraftReview = {offer, sync, cancel:shell => { const record = records.get(shell); if (record) close(record); }};
})();
</script>

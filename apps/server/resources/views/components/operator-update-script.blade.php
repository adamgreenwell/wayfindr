<script>
(function () {
    'use strict';
    const root = document.querySelector('[data-operator-updates]');
    if (!root) return;
    const boot = JSON.parse(document.getElementById('operator-update-bootstrap').textContent);
    const copy = JSON.parse(document.getElementById('operator-update-copy').textContent);
    const find = (name) => root.querySelector('[data-update-' + name + ']');
    const words = (group, key) => Object.prototype.hasOwnProperty.call(copy[group] || {}, key)
        ? copy[group][key] : (copy.errors || {}).unknown || '';
    const say = (name, text) => { const node = find(name); if (node && node.textContent !== text) node.textContent = text; };
    const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
    const hash = /^[0-9a-f]{64}$/;
    const tag = /^v(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/;
    const phases = ['accepted', 'preparing', 'blocked', 'downloading', 'protecting', 'applying', 'restarting', 'verifying', 'succeeded', 'failed_safe', 'cancelled', 'recovery_required', 'reconciliation_required'];
    const stages = ['preparing', 'downloading', 'protecting', 'applying', 'restarting', 'verifying'];
    const active = ['accepted', 'preparing', 'downloading', 'protecting', 'applying', 'restarting', 'verifying'];
    const storageKey = 'wayfindr:update:' + boot.actor_id;
    const dialog = find('dialog');
    let restoreFocus = null;
    let installation = null, eligible = false, stopped = false, busy = false, connected = false, lifecyclePaused = false;
    let selected = null, operation = null, review = null, reviewBound = false, pending = null;
    let authUntil = 0, revision = -1, generation = null, observedAt = null, lastStage = 'preparing';
    let epoch = 0, timer = null, polling = false, failures = 0, reviewing = false;
    let historyCursor = 0, historyRevision = null, historyBusy = false;
    let historyRecords = [], discoveryPending = false;
    let pinnedTag = null, pinnedPlan = null, pinnedTarget = null, pinnedSource = null;

    function readSaved() {
        try {
            const saved = JSON.parse(localStorage.getItem(storageKey));
            if (!saved || saved.schema !== 1 || typeof saved.installation_id !== 'string'
                || (saved.operation_id !== null && !uuid.test(saved.operation_id))
                || (saved.plan_id !== null && !hash.test(saved.plan_id))
                || (saved.requested_tag !== null && !tag.test(saved.requested_tag))) return null;
            const p = saved.pending;
            if (p && (!['plan', 'recheck', 'start', 'cancel'].includes(p.action) || !uuid.test(p.request_id)
                || (['start', 'cancel'].includes(p.action) && (!uuid.test(p.operation_id) || !hash.test(p.plan_id)))
                || (['plan', 'recheck'].includes(p.action) && !tag.test(p.release_tag)))) return null;
            if (p) {
                const allowed = ['plan', 'recheck'].includes(p.action)
                    ? ['action', 'request_id', 'release_tag'] : ['action', 'request_id', 'operation_id', 'plan_id'];
                if (Object.keys(p).some((key) => !allowed.includes(key))) return null;
                if (['start', 'cancel'].includes(p.action)
                    && (p.operation_id !== saved.operation_id || p.plan_id !== saved.plan_id)) return null;
            }
            return saved;
        } catch (error) { return null; }
    }

    function persist() {
        if (!installation) return;
        const saved = {
            schema: 1, installation_id: installation, operation_id: selected,
            plan_id: operation && operation.plan_id || null,
            requested_tag: pinnedTag || operation && operation.release_tag || pending && pending.release_tag || null,
            revision, generation, stage: lastStage, phase: operation && operation.phase || null,
            created_at: operation && operation.created_at || null, updated_at: operation && operation.updated_at || null, observed_at: observedAt, pending,
        };
        try { localStorage.setItem(storageKey, JSON.stringify(saved)); }
        catch (error) { say('message', words('connection', 'storage_unavailable')); }
    }

    function endpoint(action, id) {
        const raw = boot.urls[action];
        if (typeof raw !== 'string' || (raw.includes('__OPERATION__') && !uuid.test(id || ''))) throw new Error('endpoint');
        const url = new URL(raw.replace('__OPERATION__', id || ''), window.location.href);
        if (url.origin !== window.location.origin) throw new Error('endpoint');
        return url.href;
    }

    async function request(action, payload, id, query) {
        const url = new URL(endpoint(action, id));
        Object.entries(query || {}).forEach(([key, value]) => url.searchParams.set(key, value));
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(url.href, {
                method: payload ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
                redirect: 'error', signal: controller.signal,
                headers: { Accept: 'application/json', ...(payload ? {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                } : {}) },
                ...(payload ? { body: JSON.stringify(payload) } : {}),
            });
            if ([401, 403, 419].includes(response.status)) {
                // A returning login page is not a returning updater. Stop reads
                // and all writes; credentials/CSRF are refreshed by signing in.
                stopped = true; authUntil = 0; clearTimeout(timer);
                say('connection', words('connection', response.status === 403 ? 'authorization_lost' : 'session_lost'));
                updateControls();
                throw Object.assign(new Error('session'), { session: true });
            }
            const data = await response.json();
            if (!response.ok) {
                const reason = typeof data.reason === 'string' ? data.reason.replace(/^helper_refused:/, '') : 'unknown';
                if (response.status === 428) authUntil = 0;
                throw Object.assign(new Error('refused'), { reason, status: response.status });
            }
            if (data.schema !== 1) throw new Error('invalid');
            return data;
        } finally { clearTimeout(timeout); }
    }

    function explain(error) { return words('errors', error.reason || 'unknown'); }
    function proofFresh() { return authUntil > Date.now() / 1000; }
    function prepared() {
        return operation && operation.phase === 'blocked' && operation.checkpoint === 'plan_reported'
            && operation.error === 'execution_not_available' && !operation.operator?.start;
    }
    function canPrepare() {
        return eligible && review && review.status === 'update_available'
            && review.release_requirements?.migration_blocked === false && tag.test(review.target?.tag || '')
            && !(operation && active.includes(operation.phase));
    }
    function canRetry() {
        if (!pending) return false;
        if (['plan', 'recheck'].includes(pending.action)) return !selected;
        if (!operation || selected !== pending.operation_id || operation.plan_id !== pending.plan_id) return false;
        if (pending.action === 'cancel') return operation.mutation_started === false
            && !!operation.operator?.start && ['downloading', 'protecting'].includes(operation.phase);
        return prepared() && reviewBound && find('confirm').checked;
    }
    function updateControls() {
        const locked = busy || stopped || lifecyclePaused || !eligible || !connected;
        find('controls').hidden = !eligible;
        find('prepare').disabled = locked || !proofFresh() || !canPrepare() || !!pending || !!selected;
        find('recheck').disabled = locked || !proofFresh() || !canPrepare() || !!pending;
        find('confirm').disabled = locked || !prepared() || !reviewBound;
        find('start').disabled = locked || !proofFresh() || !prepared() || !reviewBound || !!pending || !find('confirm').checked;
        find('cancel').disabled = locked || !proofFresh() || !operation || !operation.operator?.start
            || operation.mutation_started !== false || !['downloading', 'protecting'].includes(operation.phase) || !!pending;
        find('retry').hidden = !pending;
        find('retry').disabled = locked || !proofFresh() || !canRetry();
        find('open').hidden = !selected;
        find('check').disabled = busy || stopped;
        find('auth').querySelector('button').disabled = locked;
        if (find('auth-open')) find('auth-open').hidden = stopped || !eligible || !operation?.operator?.start || proofFresh() || operation?.mutation_started !== false;
        if (find('signin')) find('signin').hidden = !stopped;
    }

    function node(tagName, text, unknownLanguage) {
        const element = document.createElement(tagName);
        element.textContent = text == null ? '' : String(text);
        if (unknownLanguage) element.setAttribute('lang', '');
        return element;
    }
    function section(label, text) {
        const fragment = document.createElement('div');
        fragment.append(node('h3', label), node('p', text));
        return fragment;
    }
    function fact(container, label, value, unknownLanguage = true) {
        const row = document.createElement('div');
        row.append(node('dt', label), node('dd', value, unknownLanguage));
        container.append(row);
    }
    function renderReview(plan) {
        review = plan; reviewBound = false; find('confirm').checked = false;
        say('target', plan.target?.tag || words('labels', 'unknown'));
        find('target').setAttribute('lang', plan.target?.tag ? '' : document.documentElement.lang);
        const container = find('review'); container.replaceChildren();
        say('message', words('review', plan.status === 'update_available' ? 'ready' : plan.status));
        const facts = document.createElement('dl'); facts.className = 'update-facts';
        fact(facts, words('labels', 'platform'), plan.target?.platform || words('labels', 'unknown'), !!plan.target?.platform);
        fact(facts, words('review', 'minimum_upgrade_from'), plan.release_requirements?.minimum_upgrade_from || words('labels', 'unknown'), !!plan.release_requirements?.minimum_upgrade_from);
        container.append(facts);
        for (const [key, values, emptyKey] of [
            ['release_actions', plan.release_requirements?.actions || [], 'release_actions_empty'],
            ['notices', plan.advisory_notices || [], 'notices_empty'],
        ]) {
            const block = section(words('labels', key), values.length ? '' : words('review', emptyKey));
            const list = document.createElement('ul'); list.className = 'update-review-list';
            values.forEach((item) => {
                const entry = document.createElement('li');
                entry.append(node('strong', item.summary || item.id || '', true));
                if (item.detail) entry.append(node('p', item.detail, true));
                if (item.phase) entry.append(node('p', words('review', 'action_phase_' + item.phase)));
                if (item.disposition) entry.append(node('p', words('review', 'action_' + item.disposition)));
                list.append(entry);
            });
            block.append(list); container.append(block);
        }
        const checks = plan.release_requirements?.check_evidence || [];
        const checkBlock = section(words('labels', 'checks'), Object.keys(checks).length ? '' : words('review', 'checks_empty'));
        Object.entries(checks).forEach(([key, value]) => {
            const line = document.createElement('p');
            line.append(node('code', key, true), node('span', ' — ' + words('labels', value === true || value?.passed === true ? 'passed' : value === false || value?.passed === false ? 'failed' : 'not_checked')));
            checkBlock.append(line);
        });
        container.append(checkBlock);
        container.append(section(words('labels', 'migrations'), words('review', 'migration_unknown')));
        const backup = section(words('labels', 'backup_policy'), words('review', 'policy_unassessed'));
        const policy = plan.managed?.optional_backup_policy || {};
        for (const [key, label] of [['require_remote_backup', 'remote_backup'], ['require_restore_proof', 'restore_proof']]) {
            backup.append(node('p', words('review', label) + ' — ' + words('labels', policy[key] === true ? 'required' : policy[key] === false ? 'not_required' : 'unknown')));
        }
        container.append(backup);
        container.append(node('p', words('review', plan.release_requirements?.migration_blocked === false ? 'requirements_clear' : 'requirements_blocked')));
        container.append(section(words('labels', 'interruption'), words('review', 'interruption_expected')));
        const recovery = section(words('labels', 'recovery'), words('review', 'recovery_before_mutation'));
        recovery.append(node('p', words('review', 'recovery_after_mutation')), node('p', words('review', 'no_automatic_restore')));
        container.append(recovery);
        const notes = document.createElement('details'); notes.className = 'details-disclosure';
        notes.append(node('summary', words('labels', 'release_notes')));
        const body = node('p', plan.target?.release_notes || words('review', 'release_notes_empty'), !!plan.target?.release_notes);
        body.style.whiteSpace = 'pre-wrap'; notes.append(body); container.append(notes);
        updateControls();
    }

    function stageFor(record) {
        if (stages.includes(record.phase)) return record.phase;
        if (record.phase === 'succeeded') return 'verifying';
        const checkpointStages = {
            accepted: 'preparing', prepare_started: 'preparing', plan_reported: 'preparing',
            apply_started: 'downloading', target_download_intent: 'downloading', target_verified: 'downloading',
            protection_started: 'protecting', fenced: 'protecting', drained: 'protecting', backup_verified: 'protecting', data_protected: 'protecting',
            migration_intent: 'applying', migrations_verified: 'applying', target_restart_intent: 'restarting', target_services_started: 'restarting',
            runtime_verified: 'verifying', configuration_commit_intent: 'verifying', configuration_committed: 'verifying',
            apply_release_intent: 'verifying', serving_verified: 'verifying',
        };
        return checkpointStages[record.checkpoint] || lastStage;
    }
    function succeeded(record) {
        const a = record.apply, t = record.target;
        return record.phase === 'succeeded' && record.checkpoint === 'serving_verified'
            && record.error === null && record.mutation_started === true && a?.phase === 'verified'
            && a.migration_verified === true && a.services_verified === true && a.origin_verified === true
            && a.configuration_committed === true && a.hold_owned === false
            && record.protection?.phase === 'retained' && record.protection.hold_owned === false
            && tag.test(record.release_tag) && t?.tag === record.release_tag && t.version === record.release_tag.slice(1)
            && a.index_digest === t.image_digest && /^sha256:[0-9a-f]{64}$/.test(t.image_digest || '');
    }
    function outcome(record) {
        if (record.phase === 'succeeded' && !succeeded(record)) return 'reconciliation_required';
        if (['failed_safe', 'cancelled'].includes(record.phase)
            && (record.checkpoint !== 'previous_serving_verified' || record.mutation_started !== false
                || record.apply?.phase !== 'fallback' || record.apply.services_verified !== true
                || record.apply.origin_verified !== true || record.apply.hold_owned !== false
                || record.protection?.hold_owned !== false)) return 'reconciliation_required';
        return prepared() ? 'preparing' : record.phase;
    }
    function renderClock() {
        if (!operation) return;
        const terminal = !active.includes(operation.phase);
        const end = terminal ? operation.updated_at : Date.now() / 1000;
        const elapsed = Number.isFinite(end) && Number.isFinite(operation.created_at) ? Math.max(0, Math.floor(end - operation.created_at)) : null;
        say('elapsed', elapsed === null ? words('notices', 'elapsed_unavailable') : Math.floor(elapsed / 60) + ':' + String(elapsed % 60).padStart(2, '0'));
        say('last-seen', observedAt ? new Date(observedAt).toLocaleTimeString(document.documentElement.lang || undefined) : '—');
    }
    function renderOperation() {
        if (!operation) return;
        const state = outcome(operation);
        const outcomeNode = find('outcome'); outcomeNode.dataset.outcome = state;
        say('outcome', (!connected ? words('connection', 'last_known') + ' ' : '')
            + (prepared() ? words('review', 'prepared') : words('outcomes', state) + ': ' + words('outcome_details', state)));
        say('error', operation.error && operation.error !== 'execution_not_available' ? words('errors', operation.error) : '');
        say('identity', (operation.source?.version || boot.current.version || '?') + ' → ' + operation.release_tag);
        const stage = stages.indexOf(lastStage);
        root.querySelectorAll('[data-update-stage]').forEach((item) => {
            const index = stages.indexOf(item.dataset.updateStage);
            item.dataset.state = index === stage ? 'current' : index < stage || state === 'succeeded' ? 'confirmed' : 'waiting';
            if (index === stage) item.setAttribute('aria-current', 'step'); else item.removeAttribute('aria-current');
        });
        say('boundary', operation.mutation_started === true ? words('notices', 'mutation_started')
            : operation.mutation_started !== false ? ''
                : operation.operator?.cancel ? words('notices', 'cancel_pending') : words('notices', 'cancel_boundary'));
        const details = find('details'); details.replaceChildren();
        fact(details, words('labels', 'operation'), selected);
        fact(details, words('labels', 'plan_id'), operation.plan_id || words('labels', 'unknown'), !!operation.plan_id);
        fact(details, words('labels', 'revision'), revision);
        fact(details, words('labels', 'backup_policy'), operation.protection?.custody_verified === true ? words('labels', 'verified') : words('labels', 'unverified'), false);
        const events = find('events'); events.replaceChildren();
        (operation.events || []).slice(-30).forEach((event) => {
            events.append(node('li', words('event_codes', event.code) + ' · ' + String(event.revision)));
        });
        renderClock(); updateControls();
    }

    function openDialog() {
        if (!selected || dialog.open) return;
        restoreFocus = document.activeElement;
        dialog.showModal(); find('close').focus();
    }
    dialog.addEventListener('close', () => {
        if (restoreFocus?.isConnected && !restoreFocus.disabled
            && restoreFocus !== document.body && restoreFocus !== document.documentElement) restoreFocus.focus();
        else find('open').focus();
    });
    find('close').addEventListener('click', () => dialog.close());
    find('open').addEventListener('click', openDialog);

    function reconcilePending(record) {
        if (!pending) return;
        const receipt = record.operator?.[pending.action === 'recheck' || pending.action === 'plan' ? 'prepare' : pending.action];
        const requestId = pending.action === 'plan' || pending.action === 'recheck' ? record.request_id : receipt?.request_id;
        if (requestId === pending.request_id && receipt?.actor?.id === boot.actor_id) pending = null;
    }
    function acceptSnapshot(snapshot, expectedId, requestEpoch) {
        if (requestEpoch !== epoch || snapshot?.schema !== 1 || snapshot.installation_id !== installation
            || !Number.isInteger(snapshot.revision) || !uuid.test(snapshot.generation || '')) throw new Error('invalid');
        const record = snapshot.operation;
        if (!record) {
            if (expectedId) throw new Error('missing');
            return false;
        }
        if (!uuid.test(record.operation_id || '') || !phases.includes(record.phase)
            || !Number.isInteger(record.revision) || !tag.test(record.release_tag || '')
            || (expectedId && record.operation_id !== expectedId)) throw new Error('invalid');
        if (selected === record.operation_id && record.revision < revision) throw new Error('regressed');
        // Generation changes never erase the durable revision floor. A lower
        // revision needs investigation, not invented rollback or completion.
        if (selected && record.operation_id !== selected) throw new Error('selection');
        if ((pinnedTag && record.release_tag !== pinnedTag)
            || (pinnedPlan && record.plan_id !== pinnedPlan)
            || (pinnedTarget && JSON.stringify(record.target) !== pinnedTarget)
            || (pinnedSource && JSON.stringify(record.source) !== pinnedSource)) throw new Error('identity');
        if (pending && ['plan', 'recheck'].includes(pending.action)
            && (record.request_id !== pending.request_id || record.operator?.prepare?.actor?.id !== boot.actor_id)) throw new Error('receipt');
        pinnedTag = record.release_tag;
        // Preparation initially has no target/plan facts. Once recorded they
        // are immutable across progress, reconnects and helper generations.
        if (record.plan_id) pinnedPlan = record.plan_id;
        if (record.target) pinnedTarget = JSON.stringify(record.target);
        if (record.source) pinnedSource = JSON.stringify(record.source);
        selected = record.operation_id; operation = record; revision = record.revision;
        generation = snapshot.generation; observedAt = Date.now(); lastStage = stageFor(record);
        connected = true; reconcilePending(record); persist(); renderOperation();
        say('connection', words('connection', 'connected')); updateControls();
        if (prepared() && !reviewBound) loadBoundReview();
        return true;
    }

    async function loadBoundReview() {
        if (reviewing || !selected || stopped) return;
        const id = selected, currentEpoch = epoch;
        reviewing = true;
        try {
            const data = await request('review', null, id);
            if (epoch !== currentEpoch || selected !== id || !prepared()) return;
            if (data.review?.plan_id !== operation.plan_id || data.review?.target?.tag !== operation.release_tag
                || data.review?.status !== 'update_available' || data.review?.release_requirements?.migration_blocked !== false) throw new Error('invalid');
            renderReview(data.review); reviewBound = true;
            say('message', words('review', 'prepared')); updateControls();
        } catch (error) {
            if (epoch === currentEpoch && !error.session) { reviewBound = false; say('message', explain(error)); updateControls(); }
        } finally { reviewing = false; }
    }

    function schedule() {
        clearTimeout(timer);
        if (!stopped && !lifecyclePaused) timer = setTimeout(poll, Math.min(30000, 2000 * Math.pow(2, Math.min(failures, 4))));
    }
    async function poll() {
        if (polling || stopped || lifecyclePaused || !installation) return;
        polling = true;
        const id = selected, currentEpoch = epoch;
        try {
            const data = await request('status', null, null, id ? { operation_id: id } : null);
            if (currentEpoch !== epoch) return;
            if (!id && pending && ['plan', 'recheck'].includes(pending.action)
                && (data.snapshot?.operation?.request_id !== pending.request_id
                    || data.snapshot?.operation?.operator?.prepare?.actor?.id !== boot.actor_id)) {
                // The latest pointer may have moved after a lost preparation
                // reply. Search one bounded history page for our exact receipt;
                // otherwise keep the original request for explicit retry.
                if (data.snapshot?.schema !== 1 || data.snapshot.installation_id !== installation) throw new Error('invalid');
                const records = await request('history', null, null, { cursor: 0, limit: 50 });
                if (records.snapshot?.installation_id !== installation || !Array.isArray(records.snapshot?.operations)) throw new Error('invalid');
                const receipt = records.snapshot.operations.find((record) => record.request_id === pending?.request_id
                    && record.operator?.prepare?.actor?.id === boot.actor_id && record.release_tag === pending?.release_tag);
                if (receipt && uuid.test(receipt.operation_id || '')) {
                    const exact = await request('status', null, null, { operation_id: receipt.operation_id });
                    acceptSnapshot(exact.snapshot, receipt.operation_id, currentEpoch); openDialog();
                } else {
                    connected = true; say('message', words('connection', 'request_uncertain')); updateControls();
                }
                failures = 0; return;
            }
            if (!id && data.snapshot?.operation && data.snapshot.active_operation !== data.snapshot.operation.operation_id
                && !(pending && data.snapshot.operation.request_id === pending.request_id)) {
                // An old terminal preparation is history, not a new update.
                if (data.snapshot.schema !== 1 || data.snapshot.installation_id !== installation
                    || !Number.isInteger(data.snapshot.revision) || !uuid.test(data.snapshot.generation || '')) throw new Error('invalid');
                connected = true; updateControls();
                failures = 0; return;
            }
            const changed = acceptSnapshot(data.snapshot, id, currentEpoch);
            connected = true; updateControls();
            failures = 0;
            if (changed && !id) openDialog();
        } catch (error) {
            if (currentEpoch === epoch && !error.session) {
                failures += 1; connected = false; reviewBound = false;
                renderOperation();
                say('connection', words('connection', selected ? 'reconnecting' : 'unknown'));
                if (selected) say('message', words('connection', 'last_known'));
                updateControls();
            }
        } finally {
            polling = false;
            if (selected || pending || discoveryPending || failures) schedule();
        }
    }

    async function candidate() {
        if (stopped || busy) return;
        busy = true; updateControls(); say('message', words('review', 'loading'));
        const currentEpoch = epoch;
        try {
            const data = await request('candidate');
            if (epoch !== currentEpoch || (operation && active.includes(operation.phase))) return;
            if (!data.review?.target || !tag.test(data.review.target.tag || '')) throw new Error('invalid');
            eligible = data.managed_execution_available === true;
            renderReview(data.review);
            const ownership = data.review.installation?.ownership || 'unknown';
            const instructions = (copy.ownership || {})[ownership] || (copy.ownership || {}).unknown;
            say('ownership', instructions?.guidance || words('notices', 'manual_only'));
        } catch (error) { if (!error.session) { review = null; reviewBound = false; say('message', explain(error)); } }
        finally { busy = false; updateControls(); }
    }

    async function history(more) {
        if (historyBusy || stopped || !installation) return;
        historyBusy = true; find('history-more').disabled = true;
        try {
            const data = await request('history', null, null, { cursor: more ? historyCursor : 0, limit: 10 });
            const snapshot = data.snapshot;
            if (snapshot?.installation_id !== installation || !Array.isArray(snapshot.operations)) throw new Error('invalid');
            if (more && historyRevision !== snapshot.revision) {
                say('history-message', words('history', 'changed'));
                historyCursor = 0; historyRecords = []; historyRevision = null;
                historyBusy = false; return history(false);
            }
            historyRevision = snapshot.revision; historyCursor = snapshot.next_cursor;
            historyRecords = more ? historyRecords.concat(snapshot.operations) : snapshot.operations;
            const container = find('history'); container.replaceChildren();
            historyRecords.forEach((record) => {
                if (!uuid.test(record.operation_id || '') || !tag.test(record.release_tag || '') || !phases.includes(record.phase)) return;
                const button = document.createElement('button'); button.type = 'button'; button.className = 'management-link';
                const label = node('span', record.release_tag, true);
                const state = node('span', words('outcomes', record.phase), false);
                const when = Number.isFinite(record.created_at) ? new Date(record.created_at * 1000).toLocaleString(document.documentElement.lang || undefined) : words('labels', 'unknown');
                const metadata = node('span', when + ' · ' + record.operation_id.slice(0, 8)); metadata.className = 'lede';
                const group = document.createElement('span'); group.append(label, metadata);
                button.append(group, state); button.setAttribute('aria-label', words('history', 'open_operation') + ' ' + record.release_tag + ' ' + when + ' ' + record.operation_id);
                button.addEventListener('click', () => selectOperation(record.operation_id)); container.append(button);
            });
            say('history-message', historyRecords.length ? '' : words('history', 'empty'));
            find('history-more').hidden = snapshot.has_more !== true;
        } catch (error) { if (!error.session) say('history-message', words('history', 'load_failed')); }
        finally { historyBusy = false; find('history-more').disabled = false; }
    }

    async function selectOperation(id) {
        if (busy || pending || stopped || !uuid.test(id)) return;
        epoch += 1; selected = id; operation = null; connected = false; reviewBound = false; revision = -1; lastStage = 'preparing';
        pinnedTag = null; pinnedPlan = null; pinnedSource = null; pinnedTarget = null;
        find('confirm').checked = false; updateControls(); say('outcome', words('connection', 'unknown'));
        say('connection', words('connection', 'unknown')); openDialog();
        // A stale in-flight read may finish, but its epoch cannot select the
        // wrong operation. Its finally schedules the new exact-ID read.
        if (polling) schedule(); else await poll();
    }

    async function submitAction(action, retry) {
        if (busy || stopped || lifecyclePaused || !eligible || !connected || !proofFresh()) { say('auth-message', words('reauth', 'expired')); return; }
        if (!retry && pending) return;
        if (retry && !pending) return;
        if (retry && !canRetry()) return;
        if (!retry) {
            if (action === 'start' && (!prepared() || !reviewBound || !find('confirm').checked)) return;
            if (action === 'cancel' && find('cancel').disabled) return;
            if (['plan', 'recheck'].includes(action) && !canPrepare()) return;
            const requestId = window.crypto.randomUUID();
            pending = ['plan', 'recheck'].includes(action)
                ? { action, request_id: requestId, release_tag: review.target.tag }
                : { action, request_id: requestId, operation_id: selected, plan_id: operation.plan_id };
        }
        const intent = { ...pending };
        busy = true; persist(); updateControls();
        if (['plan', 'recheck'].includes(intent.action)) {
            epoch += 1; selected = null; operation = null; revision = -1; lastStage = 'preparing'; reviewBound = false;
            pinnedTag = intent.release_tag; pinnedPlan = null; pinnedSource = null; pinnedTarget = null;
            persist();
        }
        const currentEpoch = epoch;
        try {
            const payload = { request_id: intent.request_id, ...(['plan', 'recheck'].includes(intent.action)
                ? { release_tag: intent.release_tag } : { plan_id: intent.plan_id }) };
            const data = await request(intent.action, payload, intent.operation_id);
            acceptSnapshot(data.snapshot, intent.operation_id || null, currentEpoch);
            openDialog(); failures = 0;
        } catch (error) {
            if (!error.session) {
                say('message', error.reason ? explain(error) : words('connection', 'request_uncertain'));
                say('connection', words('connection', 'last_known'));
                if (!error.reason || error.status >= 500) connected = false;
                // These explicit refusals happened before admission. They can
                // be rechecked as a new review; uncertain writes retain IDs.
                if (['stale_plan', 'plan_not_ready', 'cancel_unavailable'].includes(error.reason)) pending = null;
            }
            // Preserve this exact intent, including its ID, until its receipt
            // arrives or the operator explicitly retries. Polls never POST.
        } finally { busy = false; persist(); updateControls(); schedule(); }
    }

    find('auth').addEventListener('submit', async (event) => {
        event.preventDefault(); if (busy || stopped || !eligible) return;
        const form = find('auth');
        const password = form.querySelector('[name="current_password"]');
        const code = form.querySelector('[name="one_time_code"]');
        const payload = { current_password: password.value, ...(code ? { one_time_code: code.value } : {}) };
        password.value = ''; if (code) code.value = '';
        busy = true; authUntil = 0; updateControls(); say('auth-message', words('reauth', 'confirming'));
        try {
            const data = await request('reauthenticate', payload);
            if (data.reauthenticated !== true || !Number.isInteger(data.expires_at)) throw new Error('invalid');
            authUntil = data.expires_at; say('auth-message', words('reauth', 'confirmed'));
        } catch (error) { if (!error.session) say('auth-message', words('reauth', 'failed')); }
        finally { payload.current_password = ''; delete payload.one_time_code; busy = false; updateControls(); }
    });
    find('check').addEventListener('click', () => { if (prepared()) loadBoundReview(); else candidate(); history(false); });
    find('confirm').addEventListener('change', updateControls);
    find('prepare').addEventListener('click', () => submitAction('plan', false));
    find('recheck').addEventListener('click', () => submitAction('recheck', false));
    find('start').addEventListener('click', () => submitAction('start', false));
    find('cancel').addEventListener('click', () => { if (!find('cancel').disabled && window.confirm(words('notices', 'cancel_confirm'))) submitAction('cancel', false); });
    find('retry').addEventListener('click', () => submitAction(null, true));
    find('history-more').addEventListener('click', () => history(true));
    find('auth-open')?.addEventListener('click', () => {
        dialog.close(); find('auth').querySelector('[name="current_password"]').focus();
    });
    window.addEventListener('online', () => { if (!stopped) { failures = 0; clearTimeout(timer); poll(); } });
    window.addEventListener('storage', (event) => {
        if (event.key === storageKey && !stopped) { clearTimeout(timer); poll(); }
    });
    document.addEventListener('visibilitychange', () => { if (!document.hidden && !stopped) { clearTimeout(timer); poll(); } });
    window.addEventListener('pagehide', () => { lifecyclePaused = true; connected = false; clearTimeout(timer); updateControls(); });
    window.addEventListener('pageshow', () => {
        if (lifecyclePaused) { lifecyclePaused = false; if (!stopped) poll(); }
    });
    setInterval(() => { renderClock(); updateControls(); }, 1000);

    async function bootPage() {
        const saved = readSaved();
        try {
            const data = await request('capabilities');
            installation = data.installation?.installation_id || null;
            eligible = data.managed_execution_available === true;
            const ownership = data.installation?.ownership || 'unknown';
            const instructions = (copy.ownership || {})[ownership] || (copy.ownership || {}).unknown;
            say('ownership', instructions?.guidance || words('notices', 'manual_only'));
            if (saved && installation && saved.installation_id === installation) {
                selected = saved.operation_id; pending = saved.pending;
                pinnedTag = saved.requested_tag; pinnedPlan = saved.plan_id;
                revision = Number.isInteger(saved.revision) ? saved.revision : -1;
                generation = saved.generation; observedAt = saved.observed_at;
                lastStage = stages.includes(saved.stage) ? saved.stage : 'preparing';
                if (selected) {
                    operation = { operation_id: selected, plan_id: saved.plan_id, release_tag: saved.requested_tag,
                        phase: phases.includes(saved.phase) ? saved.phase : 'reconciliation_required',
                        created_at: saved.created_at, updated_at: saved.updated_at || saved.created_at };
                    // Storage is a bookmark and last-known display only. It
                    // supplies no admission/success proof or enabled control.
                    renderOperation(); say('connection', words('connection', 'last_known')); openDialog();
                }
            } else if (saved && installation) {
                try { localStorage.removeItem(storageKey); } catch (error) { /* bookmarks are optional */ }
            }
            updateControls();
            if (installation) { await poll(); history(false); }
            if (!selected && !pending && !stopped) candidate();
        } catch (error) {
            if (!error.session) { eligible = false; say('message', words('notices', 'helper_unavailable')); updateControls(); }
        }
    }
    bootPage();
})();
</script>

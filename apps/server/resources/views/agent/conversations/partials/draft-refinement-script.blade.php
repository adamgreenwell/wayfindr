<script>
(() => {
    if (window.wayfindrDraftRefinement) return;
    const mounted = new WeakSet(), records = new Set();
    const mount = shell => {
        if (mounted.has(shell)) return;
        const panel = shell.querySelector('[data-draft-refinement]'), body = shell.querySelector('[data-reply-body]');
        if (!panel || !body || !window.wayfindrAssistDraftReview) return;
        mounted.add(shell);
        const lifecycle = new AbortController(), signal = lifecycle.signal;
        const requestButton = panel.querySelector('[data-refinement-request]'), cancelButton = panel.querySelector('[data-refinement-cancel]');
        const action = panel.querySelector('[data-refinement-action]'), tone = panel.querySelector('[data-refinement-tone]'), language = panel.querySelector('[data-refinement-language]');
        const status = panel.querySelector('[data-refinement-status]');
        let copy;
        try { copy = JSON.parse(panel.dataset.copy); } catch (error) { return; }
        const uuid = () => window.crypto?.randomUUID?.();
        let revision = uuid(), active = null, timer = null, expiryTimer = null, cooldownTimer = null, cooldown = 0;
        const now = () => window.performance.now();
        const bool = (element, property, value) => { if (element[property] !== value) element[property] = value; };
        const usable = () => shell.isConnected && panel.isConnected && body.isConnected && shell.querySelector('[data-reply-body]') === body
            && !body.readOnly && !body.disabled && body.closest('form')?.dataset.submitting !== 'true'
            && body.closest('form')?.getAttribute('aria-busy') !== 'true' && !shell.closest('[hidden]')
            && document.visibilityState === 'visible' && document.prerendering !== true;
        const message = (value, announce = true) => {
            status.textContent = value || '';
            if (value && announce) window.dispatchEvent(new CustomEvent('wayfindr:assist-action', {detail:{message:value}}));
        };
        const sync = () => {
            bool(requestButton, 'disabled', panel.dataset.available !== 'true' || !revision || !usable() || active !== null
                || !/[\p{L}\p{N}\p{P}\p{S}]/u.test(body.value) || /(?![\t\r\n])\p{Cc}|\p{Cs}/u.test(body.value)
                || Array.from(body.value).length > 4000 || now() < cooldown);
            bool(cancelButton, 'hidden', active === null);
            bool(panel.querySelector('[data-refinement-tone-field]'), 'hidden', action.value !== 'tone');
            bool(panel.querySelector('[data-refinement-language-field]'), 'hidden', action.value !== 'translate');
        };
        const wakeCooldown = () => {
            if (cooldownTimer !== null) window.clearTimeout(cooldownTimer);
            cooldownTimer = window.setTimeout(() => {
                if (now() < cooldown) { wakeCooldown(); return; }
                cooldown = 0; if (status.textContent === copy.cooldown) message(copy.review_help, false); sync();
            }, Math.max(1, Math.min(2147483647, cooldown - now())));
        };
        const token = () => body.closest('form')?.querySelector('input[name="_token"]')?.value || '';
        const boundedFetch = async (url, options) => {
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 15000);
            try { return await fetch(url, {...options, signal:controller.signal}); }
            finally { window.clearTimeout(timeout); }
        };
        const post = async (url, data, keepalive = false) => {
            const response = await boundedFetch(url, {method:'POST', credentials:'same-origin', cache:'no-store', keepalive,
                headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token()}, body:JSON.stringify(data)});
            if (!response.ok) {
                if (response.status === 429) {
                    const seconds = Number(response.headers.get('Retry-After'));
                    cooldown = now() + Math.min(31536000, Math.max(1, Number.isFinite(seconds) ? seconds : 60)) * 1000;
                    wakeCooldown();
                }
                throw new Error(response.status === 429 ? 'cooldown' : 'unavailable');
            }
            return response.json();
        };
        const stop = () => { if (timer !== null) window.clearTimeout(timer); if (expiryTimer !== null) window.clearTimeout(expiryTimer); timer = null; expiryTimer = null; };
        const cancelRemote = capture => {
            if (!capture?.generation) return;
            void post(panel.dataset.cancelUrl, {generation:capture.generation, draft_revision:capture.revision}, true).catch(() => {});
        };
        const clear = (note, announce = true, remote = true) => {
            const previous = active;
            active = null; stop();
            if (remote) cancelRemote(previous);
            window.wayfindrAssistDraftReview.cancel(shell);
            if (note) message(note, announce);
            sync();
        };
        const matchesDraft = capture => active === capture && usable() && revision === capture.revision && body.value === capture.original && now() < capture.expires;
        const read = async capture => {
            const url = new URL(panel.dataset.statusUrl, window.location.href);
            url.searchParams.set('generation', capture.generation); url.searchParams.set('draft_revision', capture.revision);
            const response = await boundedFetch(url, {headers:{Accept:'application/json'}, credentials:'same-origin', cache:'no-store'});
            if (!response.ok) throw new Error('unavailable');
            const value = await response.json();
            if (value.generation !== capture.generation || value.draft_revision !== capture.revision) throw new Error('unavailable');
            return value;
        };
        const poll = async capture => {
            if (capture.applying) return;
            if (!matchesDraft(capture)) { if (active === capture) clear(copy.stale); return; }
            try {
                const value = await read(capture);
                if (active !== capture || capture.applying) return;
                if (!matchesDraft(capture)) { clear(copy.stale); return; }
                if (!Number.isInteger(value.ttl_seconds) || value.ttl_seconds <= 0 || value.ttl_seconds > 900) { clear(copy.stale); return; }
                capture.expires = Math.min(capture.expires, now() + value.ttl_seconds * 1000);
                if (expiryTimer !== null) window.clearTimeout(expiryTimer);
                expiryTimer = window.setTimeout(() => { if (active === capture) clear(copy.stale); }, Math.max(1, capture.expires - now()));
                if (value.state === 'pending' || value.state === 'running') {
                    timer = window.setTimeout(() => void poll(capture), 2000); return;
                }
                if (value.state !== 'ready' || typeof value.preview?.candidate !== 'string' || typeof value.policy_revision !== 'string' || value.policy_revision === '') { clear(value.state === 'stale' ? copy.stale : copy.unavailable); return; }
                if (capture.previewing) {
                    if (capture.policy !== value.policy_revision || capture.candidate !== value.preview.candidate || capture.sources !== JSON.stringify(value.sources)) { clear(copy.stale); return; }
                    timer = window.setTimeout(() => void poll(capture), 2000); return;
                }
                capture.previewing = true; capture.policy = value.policy_revision; capture.candidate = value.preview.candidate; capture.sources = JSON.stringify(value.sources);
                message('', false);
                const identity = () => ({canUse:matchesDraft(capture) && now() < capture.expires,
                    generation:capture.generation, policyRevision:capture.policy, button:requestButton});
                await window.wayfindrAssistDraftReview.offer({shell, task:'refinement', forcePreview:true, preserveWhitespace:true,
                    readCurrent:identity,
                    fetchAuthorized:async stage => {
                        if (stage === 'apply') {
                            capture.applying = true;
                            if (timer !== null) window.clearTimeout(timer);
                            timer = null;
                        }
                        const fresh = stage === 'apply'
                            ? await post(panel.dataset.applyUrl, {generation:capture.generation, draft_revision:capture.revision}) : await read(capture);
                        if (!matchesDraft(capture) || fresh.state !== 'ready' || fresh.generation !== capture.generation
                            || fresh.draft_revision !== capture.revision || fresh.policy_revision !== capture.policy
                            || typeof fresh.preview?.candidate !== 'string' || JSON.stringify(fresh.sources) !== capture.sources || !Number.isInteger(fresh.ttl_seconds) || fresh.ttl_seconds <= 0 || fresh.ttl_seconds > 900) throw new Error('unavailable');
                        return {...identity(), text:fresh.preview.candidate, sources:fresh.sources};
                    },
                    onPreview:fresh => {
                        const region = shell.querySelector('[data-assist-review-sources]');
                        if (!region || !Array.isArray(fresh.sources) || fresh.sources.length > 3) { clear(copy.unavailable); return; }
                        region.replaceChildren(); region.hidden = fresh.sources.length === 0;
                        if (!fresh.sources.length) return;
                        const details = document.createElement('details'), summary = document.createElement('summary');
                        summary.textContent = copy.sources; details.append(summary);
                        fresh.sources.forEach(source => {
                            if (typeof source.title !== 'string' || typeof source.passage !== 'string') { clear(copy.unavailable); return; }
                            const title = document.createElement('strong'), passage = document.createElement('blockquote');
                            title.textContent = source.title; passage.textContent = source.passage;
                            title.setAttribute('lang', ''); passage.setAttribute('lang', ''); details.append(title, passage);
                        });
                        if (active === capture) region.append(details);
                    },
                    onInvalidated:() => clear(copy.stale, false),
                    onClosed:reason => { if (active === capture) { active = null; stop(); if (reason !== 'applied') cancelRemote(capture); sync(); } },
                    onUnavailable:() => { if (active === capture) clear(copy.unavailable, false); },
                    onApplied:() => { message(copy.applied, false); sync(); }
                });
                if (active === capture) timer = window.setTimeout(() => void poll(capture), 2000);
            } catch (error) { if (active === capture && !capture.applying) clear(copy.unavailable); }
        };
        requestButton.addEventListener('click', async () => {
            sync(); if (requestButton.disabled) return;
            // Any previous shared preview is withdrawn before capturing this exact draft.
            window.wayfindrAssistDraftReview.cancel(shell);
            const operation = {action:action.value, tone:action.value === 'tone' ? tone.value : null, language:action.value === 'translate' ? language.value : null};
            const capture = {original:body.value, revision, operation, generation:null, previewing:false, expires:now() + 900000};
            active = capture; message(copy.preparing); sync();
            expiryTimer = window.setTimeout(() => { if (active === capture) clear(copy.stale); }, 900000);
            try {
                // Only identifiers leave until a cancellable server grant has been acknowledged.
                const grant = await post(panel.dataset.authorizeUrl, {draft_revision:capture.revision});
                if (typeof grant.generation !== 'string' || !/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(grant.generation)
                    || grant.draft_revision !== capture.revision || grant.state !== 'authorized'
                    || !Number.isInteger(grant.ttl_seconds) || grant.ttl_seconds <= 0 || grant.ttl_seconds > 900) throw new Error('unavailable');
                capture.generation = grant.generation;
                if (!matchesDraft(capture)) { cancelRemote(capture); if (active === capture) clear(copy.stale, true, false); return; }
                const value = await post(panel.dataset.requestUrl, {generation:capture.generation, refinement_draft:capture.original,
                    draft_revision:capture.revision, ...capture.operation});
                if (value.generation !== capture.generation || value.draft_revision !== capture.revision
                    || !['pending','running'].includes(value.state)) throw new Error('unavailable');
                if (!matchesDraft(capture)) { cancelRemote(capture); if (active === capture) clear(copy.stale, true, false); return; }
                await poll(capture);
            } catch (error) { if (active === capture) clear(error.message === 'cooldown' ? copy.cooldown : copy.unavailable); }
        });
        cancelButton.addEventListener('click', () => { clear(copy.cancelled); (requestButton.disabled ? body : requestButton).focus(); });
        const edited = () => { revision = uuid(); if (active) clear(copy.stale); sync(); };
        body.addEventListener('input', edited); body.addEventListener('change', edited);
        shell.querySelector('[data-template-picker]')?.addEventListener('change', edited);
        [action,tone,language].forEach(select => select.addEventListener('change', () => { if (active) clear(copy.cancelled); sync(); }));
        body.closest('form')?.addEventListener('submit', () => { if (active) clear(copy.stale); });
        window.addEventListener('wayfindr:assist-source-changed', () => { if (active) clear(copy.stale); }, {signal});
        window.addEventListener('offline', () => { if (active) clear(copy.stale); }, {signal});
        window.addEventListener('online', () => { if (active) clear(copy.stale); sync(); }, {signal});
        window.addEventListener('pagehide', () => { if (active) clear(copy.stale, false); }, {signal});
        window.addEventListener('pageshow', () => { if (active) clear(copy.stale); sync(); }, {signal});
        document.addEventListener('visibilitychange', () => { if (active && document.visibilityState !== 'visible') clear(copy.stale, false); sync(); }, {signal});
        const observer = new MutationObserver(() => { if (active && !usable()) clear(copy.stale); sync(); });
        observer.observe(shell, {
            subtree:true, childList:true, attributes:true, attributeFilter:['hidden','readonly','disabled','data-submitting','aria-busy']});
        records.add({shell, usable, suspend:() => { if (active) clear(copy.stale, false); sync(); }, dispose:() => { if (active) clear(copy.stale, false); stop(); if (cooldownTimer !== null) window.clearTimeout(cooldownTimer); observer.disconnect(); lifecycle.abort(); }});
        sync();
    };
    const scan = () => {
        records.forEach(record => { if (!record.shell.isConnected) { record.dispose(); records.delete(record); } else if (!record.usable()) record.suspend(); });
        document.querySelectorAll('[data-reply-shell]').forEach(mount);
    };
    scan(); new MutationObserver(scan).observe(document.body, {childList:true, subtree:true, attributes:true, attributeFilter:['hidden']});
    window.wayfindrDraftRefinement = {scan};
})();
</script>

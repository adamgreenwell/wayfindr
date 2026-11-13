const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const test = require('node:test');

const root = path.resolve(__dirname, '../../../..');
const { JSDOM } = createRequire(path.join(root, 'packages/widget-js/package.json'))('jsdom');
const INSTALLATION = '1567a42e-bcc8-4bf9-8a57-6a48d107aefe';
const OPERATION = '11111111-2222-4333-8444-555555555555';
const OTHER_OPERATION = '66666666-7777-4888-8999-aaaaaaaaaaaa';
const PREPARE_REQUEST = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
const START_REQUEST = 'cccccccc-dddd-4eee-8fff-aaaaaaaaaaaa';
const PLAN = 'd'.repeat(64);
const GENERATION = 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff';
const NOW = 1791568800000;

function operation(overrides = {}) {
  const record = {
    operation_id: OPERATION, request_id: PREPARE_REQUEST, release_tag: 'v1.3.0',
    phase: 'downloading', checkpoint: 'target_download_intent',
    executor_generation: GENERATION, executor_version: '0.4.0', mutation_started: false,
    created_at: NOW / 1000 - 30, updated_at: NOW / 1000, revision: 5, error: null,
    source: { version: '1.2.0', commit: 'a'.repeat(40) },
    target: { tag: 'v1.3.0', version: '1.3.0', commit: 'b'.repeat(40), image_digest: 'sha256:' + 'c'.repeat(64) },
    plan_id: PLAN,
    events: [
      { revision: 1, at: NOW / 1000 - 30, code: 'operation_accepted', phase: 'accepted' },
      { revision: 4, at: NOW / 1000 - 5, code: 'operator_started', phase: 'downloading' },
      { revision: 5, at: NOW / 1000, code: 'target_download_intent', phase: 'downloading' },
    ],
    operator: {
      prepare: { actor: { id: 7 }, at: NOW / 1000 - 30, revision: 1 },
      start: { request_id: START_REQUEST, plan_id: PLAN, actor: { id: 7 }, at: NOW / 1000 - 5, revision: 4 },
      cancel: null,
    },
    protection: {
      phase: 'fencing', archive_sha256: null, manifest_sha256: null, archive_bytes: null,
      source_image_id: null, local_attachment_disks: null, external_attachment_disks: null,
      offsite_uploaded: null, offsite_verification: null, custody_verified: false,
      services_recovered: false, hold_owned: false, error: null,
    },
    apply: {
      phase: 'downloading', index_digest: null, platform_manifest_digest: null, config_digest: null,
      manifest_sha256: null, history_sha256: null, migration_receipt_sha256: null,
      runtime_receipt_sha256: null, migration_started: false, migration_verified: false,
      services_verified: false, origin_verified: false, configuration_committed: false,
      hold_owned: false, error: null,
    },
  };
  for (const [key, value] of Object.entries(overrides)) {
    record[key] = value && typeof value === 'object' && !Array.isArray(value)
      ? { ...record[key], ...value } : value;
  }
  if (!overrides.events) {
    record.events[record.events.length - 1] = {
      revision: record.revision, at: record.updated_at,
      code: record.checkpoint, phase: record.phase,
    };
  }
  return record;
}

function prepared(overrides = {}) {
  const record = operation({
    phase: 'blocked', checkpoint: 'plan_reported', error: 'execution_not_available',
    revision: 3, operator: { start: null, cancel: null },
    events: [
      { revision: 1, at: NOW / 1000 - 30, code: 'operation_accepted', phase: 'accepted' },
      { revision: 3, at: NOW / 1000, code: 'operation_blocked', phase: 'blocked' },
    ], ...overrides,
  });
  delete record.apply;
  delete record.protection;
  return record;
}

function succeeded(overrides = {}) {
  return operation({
    phase: 'succeeded', checkpoint: 'serving_verified', revision: 20, mutation_started: true,
    protection: {
      phase: 'retained', archive_sha256: 'a'.repeat(64), manifest_sha256: 'b'.repeat(64),
      archive_bytes: 1234, source_image_id: 'sha256:' + 'a'.repeat(64),
      local_attachment_disks: 1, external_attachment_disks: 0, offsite_uploaded: false,
      offsite_verification: 'not-configured', custody_verified: true, hold_owned: false,
    },
    apply: {
      phase: 'verified', index_digest: 'sha256:' + 'c'.repeat(64),
      platform_manifest_digest: 'sha256:' + 'd'.repeat(64), config_digest: 'sha256:' + 'e'.repeat(64),
      manifest_sha256: 'a'.repeat(64), history_sha256: 'b'.repeat(64),
      migration_receipt_sha256: 'c'.repeat(64), runtime_receipt_sha256: 'd'.repeat(64),
      migration_started: true, migration_verified: true, services_verified: true,
      origin_verified: true, configuration_committed: true, hold_owned: false,
    }, ...overrides,
  });
}

function snapshot(record = operation(), overrides = {}) {
  return {
    schema: 1, installation_id: INSTALLATION, revision: record?.revision ?? 5,
    helper_version: '0.4.0', generation: GENERATION, heartbeat_at: NOW / 1000,
    active_operation: record && !['blocked', 'succeeded', 'failed_safe', 'cancelled'].includes(record.phase) ? record.operation_id : null,
    operation: record, ...overrides,
  };
}

function capability(overrides = {}) {
  return {
    schema: 1, managed_execution_available: true, reason: null,
    installation: {
      ownership: 'installer-managed', runtime_profile: 'image', platform: 'linux', architecture: 'amd64',
      installation_id: INSTALLATION, enrolled: true, managed_update_eligible: true, managed_blockers: [],
      helper: { authenticated: true, protocol: 1, version: '0.4.0', capabilities: ['plan', 'status', 'start', 'history', 'cancel'] },
      manual_guidance: 'Use the installation owner to update this deployment.',
      ...overrides,
    },
  };
}

function review(overrides = {}) {
  return {
    schema: 1, plan_id: PLAN, status: 'update_available',
    source: { version: '1.2.0', commit: 'a'.repeat(40), profile: 'image' },
    target: { tag: 'v1.3.0', version: '1.3.0', commit: 'b'.repeat(40), image_digest: 'sha256:' + 'c'.repeat(64) },
    provenance: { repository: 'adamgreenwell/wayfindr', tag: 'v1.3.0', commit: 'b'.repeat(40), history_complete: true },
    release_requirements: { migration_blocked: false, serving_blocked: false, actions: [] },
    managed: { eligible: true, blockers: [] },
    ...overrides,
  };
}

function remembered(overrides = {}) {
  return {
    schema: 1, installation_id: INSTALLATION, operation_id: OPERATION, plan_id: PLAN,
    requested_tag: 'v1.3.0', revision: 5, generation: GENERATION, stage: 'downloading',
    phase: 'downloading', created_at: NOW / 1000 - 30, observed_at: NOW / 1000,
    pending: null, ...overrides,
  };
}

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

function response(body, status = 200, extras = {}) {
  return { ok: status >= 200 && status < 300, status, redirected: false,
    headers: { get: (name) => name.toLowerCase() === 'content-type' ? 'application/json' : null },
    json: async () => structuredClone(body), ...extras };
}

async function settle() {
  // Fetch response, JSON parsing, and the caller's finally block each queue a
  // microtask; a turn of the event loop drains them without advancing time.
  await new Promise((resolve) => setImmediate(resolve));
}

function inlineScript() {
  const view = fs.readFileSync(path.join(root, 'apps/server/resources/views/components/operator-update-script.blade.php'), 'utf8');
  return view.match(/<script>([\s\S]*?)<\/script>/)[1];
}

function clock(window) {
  let time = NOW;
  let nextId = 0;
  const timers = new Map();
  const RealDate = window.Date;
  window.Date = class extends RealDate {
    constructor(...args) { super(...(args.length ? args : [time])); }
    static now() { return time; }
  };
  window.setTimeout = (callback, delay = 0, ...args) => {
    const id = ++nextId;
    timers.set(id, { at: time + Math.max(0, Number(delay)), callback, args });
    return id;
  };
  window.clearTimeout = (id) => timers.delete(id);
  window.setInterval = (callback, delay) => {
    const id = ++nextId;
    timers.set(id, { at: time + Number(delay), callback, args: [], interval: Number(delay) });
    return id;
  };
  window.clearInterval = (id) => timers.delete(id);
  return {
    timers,
    async advance(milliseconds) {
      const until = time + milliseconds;
      let executions = 0;
      while (true) {
        const upcoming = [...timers].filter(([, timer]) => timer.at <= until).sort((a, b) => a[1].at - b[1].at)[0];
        if (!upcoming) break;
        assert.ok(++executions < 500, 'the poller must not schedule an unbounded timer loop');
        const [id, timer] = upcoming;
        time = timer.at;
        timers.delete(id);
        if (timer.interval) timers.set(id, { ...timer, at: time + timer.interval });
        timer.callback(...timer.args);
        await settle();
      }
      time = until;
      await settle();
    },
  };
}

// Copy fixtures exercise substitutions and status announcements without making
// the JavaScript suite depend on a PHP runtime. Page tests cover the real
// English, German, and Italian translation maps.
const COPY = {
  errors: { unknown: 'The request could not be verified.', stale_plan: 'Review changed.', reauthentication_required: 'Confirm your identity.' },
  labels: { unknown: 'Unknown', verified: 'Verified', unverified: 'Unverified', release_notes: 'Release notes' },
  review: { loading: 'Preparing the review.', ready: 'Ready for confirmation.', prepared: 'Exact release prepared.',
    up_to_date: 'This release is already running.', release_actions_empty: 'No release actions.',
    minimum_upgrade_from: 'Minimum source release', migration_unknown: 'Migration details unknown.',
    policy_unassessed: 'Backup policy checked by host.', interruption_expected: 'Services restart.',
    recovery_before_mutation: 'Previous release may be retained.', recovery_after_mutation: 'Explicit recovery after database changes.',
    no_automatic_restore: 'No automatic database restore.', release_notes_empty: 'No release notes.' },
  reauth: { confirming: 'Checking identity.', confirmed: 'Identity confirmed.', expired: 'Confirm identity again.', failed: 'Identity not confirmed.' },
  connection: { connected: 'Connected to the host', reconnecting: 'Reconnecting after an expected interruption.',
    unknown: 'Current host state is unknown.', last_known: 'Showing last confirmed host state.',
    session_lost: 'Sign in again.', authorization_lost: 'Update access ended.',
    request_uncertain: 'Request outcome is not confirmed.', storage_unavailable: 'Storage unavailable.' },
  outcomes: { preparing: 'Preparing', downloading: 'Downloading', protecting: 'Protecting data', applying: 'Applying',
    restarting: 'Restarting', verifying: 'Verifying', succeeded: 'Update completed', failed_safe: 'Update failed safely',
    cancelled: 'Update cancelled', recovery_required: 'Recovery required', reconciliation_required: 'Reconciliation required', blocked: 'Update blocked' },
  outcome_details: { succeeded: 'Verified target release is serving.', recovery_required: 'Use explicit host recovery.',
    failed_safe: 'Previous release is serving.', cancelled: 'Previous release is serving.' },
  notices: { cancel_confirm: 'Request cancellation?', mutation_started: 'Cancellation unavailable after database changes.',
    cancel_pending: 'Await confirmed cancellation.', cancel_boundary: 'Cancellation available before database changes.',
    elapsed_unavailable: 'Elapsed time unavailable.', manual_only: 'Use deployment owner tools.', helper_unavailable: 'Host unavailable.' },
  history: { empty: 'No operations.', open_operation: 'Open operation', load_failed: 'History unavailable.', changed: 'History changed.' },
  ownership: { 'installer-managed': { guidance: 'Terminal or enrolled host updater.' }, unknown: { guidance: 'Confirm deployment owner.' } },
};

function pageMarkup() {
  return fs.readFileSync(path.join(root, 'apps/server/resources/views/operator/updates.blade.php'), 'utf8')
    .replace(/\{\{--[\s\S]*?--\}\}/g, '')
    .replace(/<script[\s\S]*?<\/script>/g, '')
    .replace(/<ol class="update-stages"[\s\S]*?<\/ol>/, `<ol class="update-stages">${
      ['preparing', 'downloading', 'protecting', 'applying', 'restarting', 'verifying']
        .map((stage) => `<li data-update-stage="${stage}"><span>${stage}</span></li>`).join('')
    }</ol>`)
    .replace(/\{\{[\s\S]*?\}\}/g, '')
    .replace(/@if[^\n]*|@endif/g, '');
}

function updatesPage(t, options = {}) {
  const bootstrap = {
    schema: 1, actor_id: options.actorId ?? 7,
    current: { version: '1.2.0', commit: 'a'.repeat(40), runtime_profile: 'image' },
    two_factor_required: false,
    urls: Object.fromEntries(['capabilities', 'candidate', 'reauthenticate', 'plan', 'recheck', 'status', 'history', 'review', 'start', 'cancel', 'events']
      .map((action) => [action, '/operator/updates/' + (['review', 'start', 'cancel', 'events'].includes(action) ? '__OPERATION__/' : '') + action])),
    ...options.bootstrap,
  };
  const dom = new JSDOM(`<!doctype html><html lang="${options.language ?? 'en'}"><head><meta name="csrf-token" content="browser-csrf-token"></head><body><button id="outside">Outside</button>
    ${pageMarkup()}
    <script type="application/json" id="operator-update-bootstrap">${JSON.stringify(bootstrap)}</script>
    <script type="application/json" id="operator-update-copy">${JSON.stringify({ ...COPY, ...options.copy })}</script></body></html>`, {
    url: options.url ?? 'https://support.example.test/operator/updates', runScripts: 'outside-only',
  });
  t.after(() => dom.window.close());
  const { window } = dom;
  const time = clock(window);
  window.matchMedia = (query) => ({ matches: options.reducedMotion ?? false, media: query, addEventListener() {}, removeEventListener() {} });
  window.confirm = () => true;
  let dialogFocus;
  // jsdom has no native dialog implementation. Model the browser platform's
  // focus and cancel behavior while exercising the application's handlers.
  window.HTMLDialogElement.prototype.showModal = function () {
    dialogFocus = window.document.activeElement;
    this.open = true;
    this.querySelector('[autofocus], button:not(:disabled), [tabindex="0"]')?.focus();
  };
  window.HTMLDialogElement.prototype.close = function () {
    this.open = false;
    this.dispatchEvent(new window.Event('close'));
    if (dialogFocus?.isConnected) dialogFocus.focus();
  };
  let uuidCounter = 10;
  window.crypto.randomUUID = () => '01234567-89ab-4cde-8fab-' + String(++uuidCounter).padStart(12, '0');
  const storageKey = 'wayfindr:update:' + bootstrap.actor_id;
  if (options.remembered) window.localStorage.setItem(storageKey, JSON.stringify(options.remembered));
  for (const [key, value] of Object.entries(options.storage ?? {})) window.localStorage.setItem(key, value);
  if (options.storageBlocked) Object.defineProperty(window, 'localStorage', { get() { throw new window.DOMException('Storage blocked', 'SecurityError'); } });

  const requests = [];
  let record = options.operation ?? null;
  function defaultReply(request) {
    if (request.url.pathname.endsWith('/capabilities')) return response(options.capability ?? capability());
    if (request.url.pathname.endsWith('/candidate')) return response({ schema: 1, review: options.review ?? review(), managed_execution_available: true, reason: null });
    if (request.url.pathname.endsWith('/status')) return response({ schema: 1, snapshot: snapshot(record) });
    if (request.url.pathname.endsWith('/history')) return response({ schema: 1, snapshot: {
      schema: 1, installation_id: INSTALLATION, revision: record?.revision ?? 5, active_operation: null,
      cursor: 0, next_cursor: (options.history ?? []).length, has_more: false, operations: options.history ?? [],
    } });
    if (request.url.pathname.endsWith('/review')) return response({ schema: 1, snapshot: snapshot(record), review: options.review ?? review(), execution_available: true });
    if (request.url.pathname.endsWith('/events')) return response({ schema: 1, events: {
      operation_id: request.url.pathname.split('/').at(-2), events: record?.events ?? [],
      next_cursor: record?.revision ?? 0, has_more: false,
    } });
    if (request.url.pathname.endsWith('/reauthenticate')) return response({ schema: 1, reauthenticated: true, expires_at: window.Date.now() / 1000 + 300 });
    return response({ schema: 1, snapshot: snapshot(record) }, 202);
  }
  window.fetch = (input, init = {}) => {
    const request = { url: new URL(input, window.location.href), method: init.method ?? 'GET',
      body: init.body ? JSON.parse(init.body) : null, init, number: requests.length };
    requests.push(request);
    try { return Promise.resolve(options.fetch ? options.fetch(request, defaultReply) : defaultReply(request)); }
    catch (error) { return Promise.reject(error); }
  };
  window.eval(options.script ?? inlineScript());
  return {
    window, time, requests, storageKey,
    setOperation: (next) => { record = next; },
    find: (selector) => window.document.querySelector(selector),
    click: (name) => window.document.querySelector('[data-update-' + name + ']').click(),
    text: (name) => window.document.querySelector('[data-update-' + name + ']').textContent,
    saved: () => JSON.parse(window.localStorage.getItem(storageKey)),
    writes: (action) => requests.filter((request) => request.method === 'POST' && (!action || request.url.pathname.endsWith('/' + action))),
  };
}

async function authorize(page) {
  const form = page.find('[data-update-auth]');
  form.elements.current_password.value = 'private-password';
  form.elements.one_time_code.value = '123456';
  form.dispatchEvent(new page.window.Event('submit', { bubbles: true, cancelable: true }));
  await settle();
}

function currentStage(page) {
  return page.find('[data-update-stage][aria-current="step"]')?.dataset.updateStage ?? null;
}

test('refresh restores the same host operation with read-only requests', async (t) => {
  const page = updatesPage(t, { remembered: remembered(), operation: operation() });
  await settle();

  assert.equal(currentStage(page), 'downloading');
  assert.equal(page.writes().length, 0);
  const status = page.requests.filter((request) => request.url.pathname.endsWith('/status'));
  assert.ok(status.length > 0, 'restoring progress reads the host journal');
  assert.ok(status.every((request) => request.url.searchParams.get('operation_id') === OPERATION));
  assert.equal(page.saved().operation_id, OPERATION);
  assert.equal(page.saved().requested_tag, 'v1.3.0');
});

test('closing and reopening progress preserves the operation and never cancels it', async (t) => {
  const page = updatesPage(t, { remembered: remembered(), operation: operation() });
  await settle();
  const open = page.find('[data-update-open]');
  page.click('close');
  assert.equal(page.window.document.activeElement, open, 'an automatically opened dialog returns focus to View progress');
  open.focus();
  open.click();
  assert.equal(page.find('[data-update-dialog]').open, true);
  assert.equal(page.window.document.activeElement, page.find('[data-update-close]'));
  page.click('close');
  assert.equal(page.find('[data-update-dialog]').open, false);
  assert.equal(page.window.document.activeElement, open);
  page.click('open');
  assert.equal(page.find('[data-update-dialog]').open, true);
  assert.equal(currentStage(page), 'downloading');
  assert.equal(page.saved().operation_id, OPERATION);
  assert.equal(page.writes().length, 0);
});

test('a network outage retains the last confirmed stage without manufacturing completion', async (t) => {
  let unavailable = false;
  const page = updatesPage(t, {
    remembered: remembered(), operation: operation(),
    fetch: (request, normal) => {
      if (unavailable && request.url.pathname.endsWith('/status')) throw new TypeError('Network unavailable');
      return normal(request);
    },
  });
  await settle();
  unavailable = true;
  await page.time.advance(4000);
  assert.equal(currentStage(page), 'downloading');
  assert.equal(page.saved().revision, 5);
  assert.equal(page.saved().phase, 'downloading');
  assert.equal(page.find('[data-update-start]').disabled, true);
  assert.equal(page.find('[data-update-cancel]').disabled, true);
  assert.equal(page.writes().length, 0);
});

test('reconnecting only reads the remembered operation even when the host latest pointer changes', async (t) => {
  let calls = 0;
  const page = updatesPage(t, {
    remembered: remembered(), operation: operation(),
    fetch: (request, normal) => {
      if (!request.url.pathname.endsWith('/status')) return normal(request);
      calls++;
      if (calls === 2) return response({ schema: 1, reason: 'managed_update_hold' }, 503);
      return response({ schema: 1, snapshot: snapshot(operation(), { active_operation: OTHER_OPERATION }) });
    },
  });
  await settle();
  await page.time.advance(15000);
  assert.ok(calls >= 2);
  assert.ok(page.requests.filter((request) => request.url.pathname.endsWith('/status'))
    .every((request) => request.url.searchParams.get('operation_id') === OPERATION));
  assert.equal(page.saved().operation_id, OPERATION);
  assert.equal(page.writes().length, 0);
});

test('an older journal response cannot regress a confirmed operation revision', async (t) => {
  let old = false;
  const advanced = operation({ phase: 'restarting', checkpoint: 'target_restart_intent', revision: 10, mutation_started: true,
    apply: { phase: 'restarting', migration_started: true, migration_verified: true } });
  const page = updatesPage(t, {
    remembered: remembered(), operation: advanced,
    fetch: (request, normal) => request.url.pathname.endsWith('/status') && old
      ? response({ schema: 1, snapshot: snapshot(operation()) }) : normal(request),
  });
  await settle();
  assert.equal(currentStage(page), 'restarting');
  old = true;
  await page.time.advance(4000);
  assert.equal(currentStage(page), 'restarting');
  assert.equal(page.saved().revision, 10);
});

test('a mismatched operation in a status reply cannot replace the selected journey', async (t) => {
  const page = updatesPage(t, {
    remembered: remembered(), operation: operation(),
    fetch: (request, normal) => request.url.pathname.endsWith('/status')
      ? response({ schema: 1, snapshot: snapshot(succeeded({ operation_id: OTHER_OPERATION })) }) : normal(request),
  });
  await settle();
  assert.equal(page.saved().operation_id, OPERATION);
  assert.notEqual(page.saved().phase, 'succeeded');
  assert.equal(page.writes().length, 0);
});

for (const status of [401, 419]) {
  test(`HTTP ${status} pauses writes and polling until explicit authentication`, async (t) => {
    const page = updatesPage(t, {
      remembered: remembered(), operation: operation(),
      fetch: (request, normal) => request.url.pathname.endsWith('/status')
        ? response({ message: 'Sign in again.' }, status) : normal(request),
    });
    await settle();
    const statusReads = page.requests.filter((request) => request.url.pathname.endsWith('/status')).length;
    await page.time.advance(120000);
    for (const name of ['prepare', 'recheck', 'start', 'cancel', 'retry']) {
      assert.equal(page.find('[data-update-' + name + ']').disabled, true, `${name} cannot write after authentication expires`);
      page.click(name);
    }
    assert.equal(page.writes().length, 0);
    assert.equal(page.requests.filter((request) => request.url.pathname.endsWith('/status')).length, statusReads);
    assert.equal(page.saved().operation_id, OPERATION);
  });
}

test('unrelated actor storage cannot restore another operator selection or request', async (t) => {
  const page = updatesPage(t, { actorId: 8, storage: { 'wayfindr:update:7': JSON.stringify(remembered({
    pending: { action: 'start', request_id: START_REQUEST, operation_id: OPERATION, plan_id: PLAN, release_tag: 'v1.3.0' },
  })) } });
  await settle();
  assert.equal(page.saved(), null);
  assert.equal(page.writes().length, 0);
  assert.ok(page.requests.filter((request) => request.url.pathname.endsWith('/status'))
    .every((request) => !request.url.searchParams.has('operation_id')));
});

test('an installation mismatch discards a remembered selection instead of sending it to the new host', async (t) => {
  const page = updatesPage(t, { remembered: remembered({ installation_id: OTHER_OPERATION }) });
  await settle();
  assert.equal(page.writes().length, 0);
  assert.ok(page.requests.filter((request) => request.url.pathname.endsWith('/status'))
    .every((request) => request.url.searchParams.get('operation_id') !== OPERATION));
  assert.notEqual(page.saved()?.operation_id, OPERATION);
});

test('blocked browser storage still allows read-only host discovery and progress', async (t) => {
  const page = updatesPage(t, { storageBlocked: true, operation: operation() });
  await settle();
  assert.equal(currentStage(page), 'downloading');
  assert.equal(page.find('[data-update-open]').hidden, false);
  assert.equal(page.writes().length, 0);
});

test('reachable HTTP 200 with no journal evidence never reports success', async (t) => {
  const page = updatesPage(t, { remembered: remembered(), operation: operation(),
    fetch: (request, normal) => request.url.pathname.endsWith('/status')
      ? response({ status: 'ok' }) : normal(request) });
  await settle();
  assert.equal(currentStage(page), 'downloading');
  assert.notEqual(page.saved().phase, 'succeeded');
  assert.equal(page.find('[data-update-cancel]').disabled, true);
  assert.equal(page.writes().length, 0);
});

test('all six stages stay present with one current stage and elapsed time is not a live announcement', async (t) => {
  const page = updatesPage(t, { remembered: remembered(), operation: operation(), reducedMotion: true });
  await settle();
  assert.deepEqual(Array.from(page.window.document.querySelectorAll('[data-update-stage]'), (step) => step.dataset.updateStage),
    ['preparing', 'downloading', 'protecting', 'applying', 'restarting', 'verifying']);
  assert.equal(page.window.document.querySelectorAll('[data-update-stage][aria-current="step"]').length, 1);
  assert.equal(page.find('[data-update-elapsed]').closest('[aria-live]'), null);
  assert.equal(page.find('[data-update-dialog]').getAttribute('aria-labelledby'), 'update-progress-title');
  const styles = fs.readFileSync(path.join(root, 'apps/server/resources/views/components/operator-update-style.blade.php'), 'utf8');
  assert.match(styles, /prefers-reduced-motion:\s*reduce/);
});

test('the exact requested target becomes successful only with the host serving proof', async (t) => {
  const page = updatesPage(t, { remembered: remembered(), operation: succeeded() });
  await settle();
  assert.equal(page.find('[data-update-outcome]').dataset.outcome, 'succeeded');
  assert.match(page.text('outcome'), /Update completed/);
  assert.equal(page.saved().requested_tag, 'v1.3.0');
  assert.equal(page.saved().phase, 'succeeded');
  assert.equal(page.find('[data-update-cancel]').disabled, true);
  assert.equal(page.writes().length, 0);
});

for (const [name, changes] of [
  ['target version', { target: { version: '1.4.0' } }],
  ['target index digest', { apply: { index_digest: 'sha256:' + 'f'.repeat(64) } }],
  ['serving checkpoint', { checkpoint: 'runtime_verified' }],
  ['migration intent', { mutation_started: false }],
  ['verified apply outcome', { apply: { phase: 'verifying' } }],
  ['migration receipt', { apply: { migration_verified: false } }],
  ['service verification', { apply: { services_verified: false } }],
  ['public origin verification', { apply: { origin_verified: false } }],
  ['configuration commit', { apply: { configuration_committed: false } }],
  ['released application hold', { apply: { hold_owned: true } }],
  ['retained protection', { protection: { phase: 'captured' } }],
  ['released protection hold', { protection: { hold_owned: true } }],
]) {
  test(`a claimed success without matching ${name} remains uncertain`, async (t) => {
    // Build the completed receipt first, then alter one fact. This models a
    // returning page whose journal-shaped payload lacks a success prerequisite.
    const complete = succeeded();
    for (const [key, value] of Object.entries(changes)) complete[key] = value && typeof value === 'object'
      ? { ...complete[key], ...value } : value;
    const page = updatesPage(t, { remembered: remembered(), operation: complete });
    await settle();
    assert.notEqual(page.find('[data-update-outcome]').dataset.outcome, 'succeeded');
    assert.doesNotMatch(page.text('outcome'), /Update completed/);
    assert.equal(page.find('[data-update-cancel]').disabled, true);
  });
}

test('a same-ID later snapshot cannot declare a different requested release successful', async (t) => {
  const complete = succeeded();
  complete.release_tag = 'v1.4.0';
  complete.target = { ...complete.target, tag: 'v1.4.0', version: '1.4.0' };
  const page = updatesPage(t, { remembered: remembered(), operation: complete });
  await settle();
  assert.notEqual(page.find('[data-update-outcome]').dataset.outcome, 'succeeded');
  assert.equal(page.saved().requested_tag, 'v1.3.0');
  assert.equal(page.writes().length, 0);
});

for (const [name, changes] of [
  ['review plan', { plan_id: 'e'.repeat(64) }],
  ['source commit', { source: { commit: 'e'.repeat(40) } }],
  ['target commit', { target: { commit: 'e'.repeat(40) } }],
  ['target image', { target: { image_digest: 'sha256:' + 'e'.repeat(64) } }],
]) {
  test(`the selected operation cannot swap its ${name} after confirmation`, async (t) => {
    let changed = false;
    const page = updatesPage(t, { remembered: remembered(), operation: operation(),
      fetch: (request, normal) => request.url.pathname.endsWith('/status') && changed
        ? response({ schema: 1, snapshot: snapshot(operation({ revision: 8, ...changes })) }) : normal(request) });
    await settle();
    changed = true;
    await page.time.advance(4000);
    assert.equal(page.saved().revision, 5, 'unbound replacement facts are not a newer confirmed state');
    assert.equal(page.saved().plan_id, PLAN);
    assert.equal(page.saved().requested_tag, 'v1.3.0');
  });
}

test('password and second-factor values clear before a slow confirmation and never enter storage', async (t) => {
  const authReply = deferred();
  const page = updatesPage(t, { operation: null,
    fetch: (request, normal) => request.url.pathname.endsWith('/reauthenticate') ? authReply.promise : normal(request) });
  await settle();
  const form = page.find('[data-update-auth]');
  form.elements.current_password.value = 'sensitive-test-password';
  form.elements.one_time_code.value = 'sensitive-test-code';
  form.dispatchEvent(new page.window.Event('submit', { bubbles: true, cancelable: true }));
  assert.equal(form.elements.current_password.value, '');
  assert.equal(form.elements.one_time_code.value, '');
  assert.equal(page.writes('reauthenticate').length, 1);
  assert.equal(page.writes('reauthenticate')[0].init.headers['X-CSRF-TOKEN'], 'browser-csrf-token');
  assert.equal(page.writes('reauthenticate')[0].init.credentials, 'same-origin');
  assert.doesNotMatch(page.window.localStorage.getItem(page.storageKey) ?? '', /sensitive-test/);
  authReply.resolve(response({ schema: 1, reauthenticated: true, expires_at: NOW / 1000 + 300 }));
  await settle();
  assert.doesNotMatch(page.window.localStorage.getItem(page.storageKey) ?? '', /sensitive-test/);
});

test('lost start replies reuse one request ID only after an explicit retry, including reload', async (t) => {
  const page = updatesPage(t, { remembered: remembered({ revision: 3, phase: 'blocked', stage: 'preparing' }), operation: prepared(),
    fetch: (request, normal) => request.url.pathname.endsWith('/start') ? Promise.reject(new TypeError('Reply lost')) : normal(request) });
  await settle();
  await authorize(page);
  const confirmation = page.find('[data-update-confirm]');
  confirmation.checked = true;
  confirmation.dispatchEvent(new page.window.Event('change', { bubbles: true }));
  assert.equal(page.find('[data-update-start]').disabled, false);
  page.click('start');
  await settle();
  const original = page.writes('start')[0].body;
  assert.equal(page.saved().pending.action, 'start');
  await page.time.advance(12000);
  assert.equal(page.writes('start').length, 1, 'polling may reconcile but never resubmit');

  const saved = page.saved();
  const reloaded = updatesPage(t, { remembered: saved, operation: prepared(), fetch: (request, normal) => {
    if (!request.url.pathname.endsWith('/start')) return normal(request);
    const running = operation({ operator: { start: {
      request_id: request.body.request_id, plan_id: PLAN, actor: { id: 7 }, at: NOW / 1000, revision: 4,
    } } });
    return response({ schema: 1, snapshot: snapshot(running) }, 202);
  } });
  await settle();
  assert.equal(reloaded.writes().length, 0, 'reload only reads the original operation');
  assert.equal(reloaded.find('[data-update-retry]').disabled, true, 'reauthentication does not survive reload');
  await authorize(reloaded);
  assert.equal(reloaded.writes('start').length, 0, 'confirming identity does not replay the pending effect');
  assert.equal(reloaded.find('[data-update-retry]').disabled, true, 'a restored start requires a fresh review confirmation');
  const freshConfirmation = reloaded.find('[data-update-confirm]');
  freshConfirmation.checked = true;
  freshConfirmation.dispatchEvent(new reloaded.window.Event('change', { bubbles: true }));
  reloaded.click('retry');
  await settle();
  assert.equal(reloaded.writes('start').length, 1);
  assert.deepEqual(reloaded.writes('start')[0].body, original);
  assert.equal(reloaded.saved().pending, null);
});

test('lost cancellation replies remain pending and explicit retries keep the same action identity', async (t) => {
  let tries = 0;
  const page = updatesPage(t, { remembered: remembered(), operation: operation(), fetch: (request, normal) => {
    if (!request.url.pathname.endsWith('/cancel')) return normal(request);
    if (++tries === 1) return Promise.reject(new TypeError('Reply lost'));
    const cancelling = operation({ operator: { cancel: {
      request_id: request.body.request_id, plan_id: PLAN, actor: { id: 7 }, at: NOW / 1000, revision: 5, state: 'requested',
    } } });
    return response({ schema: 1, snapshot: snapshot(cancelling) }, 202);
  } });
  await settle();
  await authorize(page);
  assert.equal(page.find('[data-update-cancel]').disabled, false);
  page.click('cancel');
  await settle();
  const original = page.writes('cancel')[0].body;
  await page.time.advance(15000);
  assert.equal(page.writes('cancel').length, 1);
  assert.equal(page.saved().pending.action, 'cancel');
  page.click('retry');
  await settle();
  assert.equal(page.writes('cancel').length, 2);
  assert.deepEqual(page.writes('cancel')[1].body, original);
  assert.equal(page.saved().pending, null);
  assert.notEqual(page.find('[data-update-outcome]').dataset.outcome, 'cancelled');
});

test('a lost preparation reply retries the same release and request ID without automatic POSTs', async (t) => {
  let tries = 0;
  const page = updatesPage(t, { fetch: (request, normal) => {
    if (!request.url.pathname.endsWith('/plan')) return normal(request);
    if (++tries === 1) return Promise.reject(new TypeError('Reply lost'));
    return response({ schema: 1, snapshot: snapshot(prepared({ request_id: request.body.request_id })) }, 202);
  } });
  await settle();
  await authorize(page);
  assert.equal(page.find('[data-update-prepare]').disabled, false);
  page.click('prepare');
  await settle();
  const original = page.writes('plan')[0].body;
  await page.time.advance(12000);
  assert.equal(page.writes('plan').length, 1);
  assert.equal(page.saved().pending.request_id, original.request_id);
  page.click('retry');
  await settle();
  assert.equal(page.writes('plan').length, 2);
  assert.deepEqual(page.writes('plan')[1].body, original);
  assert.equal(page.saved().pending, null);
});

test('migration intent permanently removes cancellation even with fresh operator confirmation', async (t) => {
  const migrating = operation({ phase: 'applying', checkpoint: 'migration_intent', revision: 8, mutation_started: true,
    apply: { phase: 'applying', migration_started: true } });
  const page = updatesPage(t, { remembered: remembered(), operation: migrating });
  await settle();
  await authorize(page);
  assert.equal(currentStage(page), 'applying');
  assert.equal(page.find('[data-update-cancel]').disabled, true);
  page.click('cancel');
  assert.equal(page.writes('cancel').length, 0);
  assert.match(page.text('boundary'), /Cancellation unavailable/);
});

test('a previous terminal preparation stays in history and allows a new release review', async (t) => {
  const page = updatesPage(t, { operation: prepared(), history: [prepared()] });
  await settle();
  assert.equal(page.find('[data-update-open]').hidden, true, 'an old terminal preparation is not resumed as execution');
  assert.equal(page.find('[data-update-history]').querySelectorAll('button').length, 1);
  await authorize(page);
  assert.equal(page.find('[data-update-prepare]').disabled, false);
  assert.equal(page.writes().length, 1, 'only explicit password confirmation writes');
});

test('reconnection backoff remains bounded without overlapping slow status requests', async (t) => {
  let inFlight = 0;
  let maximum = 0;
  const starts = [];
  const page = updatesPage(t, { remembered: remembered(), fetch: (request, normal) => {
    if (!request.url.pathname.endsWith('/status')) return normal(request);
    inFlight++;
    maximum = Math.max(maximum, inFlight);
    starts.push(page?.window.Date.now() ?? NOW);
    return new Promise((resolve, reject) => request.init.signal.addEventListener('abort', () => {
      inFlight--;
      reject(new Error('Status timed out'));
    }, { once: true }));
  } });
  await settle();
  for (let i = 0; i < 5; i++) {
    page.window.dispatchEvent(new page.window.Event('online'));
    page.window.dispatchEvent(new page.window.StorageEvent('storage', { key: page.storageKey }));
  }
  await page.time.advance(180000);
  assert.equal(maximum, 1, 'online/storage signals cannot overlap an existing status read');
  assert.ok(starts.length >= 5 && starts.length <= 10, `bounded attempts observed: ${starts.length}`);
  assert.ok(starts.slice(1).every((at, index) => at - starts[index] >= 14000 && at - starts[index] <= 40000),
    'each timeout is followed by a bounded 4–30 second retry delay');
  assert.equal(page.writes().length, 0);
});

test('a late response from a previously viewed history operation cannot replace the new selection', async (t) => {
  const late = deferred();
  let firstReads = 0;
  const other = operation({ operation_id: OTHER_OPERATION, phase: 'protecting', checkpoint: 'drained', revision: 11,
    apply: { phase: 'protecting' }, protection: { phase: 'backing_up', hold_owned: true } });
  const page = updatesPage(t, { remembered: remembered(), operation: operation(), history: [other], fetch: (request, normal) => {
    if (!request.url.pathname.endsWith('/status')) return normal(request);
    if (request.url.searchParams.get('operation_id') === OTHER_OPERATION) return response({ schema: 1, snapshot: snapshot(other) });
    if (++firstReads === 2) return late.promise; // Deliberately ignore AbortSignal to model a late queued response.
    return normal(request);
  } });
  await settle();
  await page.time.advance(2000);
  page.find('[data-update-history] button').click();
  late.resolve(response({ schema: 1, snapshot: snapshot(succeeded()) }));
  await settle();
  assert.notEqual(page.find('[data-update-outcome]').dataset.outcome, 'succeeded');
  await page.time.advance(2000);
  assert.equal(page.saved().operation_id, OTHER_OPERATION);
  assert.equal(currentStage(page), 'protecting');
  assert.equal(page.find('[data-update-outcome]').dataset.outcome, 'protecting');
  assert.equal(page.writes().length, 0);
});

test('cross-tab bookmark changes perform reads without replaying another tab’s pending effect', async (t) => {
  const page = updatesPage(t, { remembered: remembered(), operation: operation() });
  await settle();
  const stranger = remembered({ operation_id: OTHER_OPERATION, pending: {
    action: 'cancel', request_id: START_REQUEST, operation_id: OTHER_OPERATION, plan_id: PLAN,
  } });
  page.window.localStorage.setItem(page.storageKey, JSON.stringify(stranger));
  page.window.dispatchEvent(new page.window.StorageEvent('storage', { key: page.storageKey, newValue: JSON.stringify(stranger) }));
  await settle();
  assert.equal(page.saved().operation_id, OPERATION);
  assert.equal(page.saved().pending, null);
  assert.equal(page.writes().length, 0);
  assert.ok(page.requests.filter((request) => request.url.pathname.endsWith('/status'))
    .every((request) => request.url.searchParams.get('operation_id') === OPERATION));
});

test('a reconnecting operation cannot request cancellation until the host state is confirmed again', async (t) => {
  let unavailable = false;
  const page = updatesPage(t, { remembered: remembered(), operation: operation(), fetch: (request, normal) => {
    if (unavailable && request.url.pathname.endsWith('/status')) throw new TypeError('Network lost');
    return normal(request);
  } });
  await settle();
  await authorize(page);
  assert.equal(page.find('[data-update-cancel]').disabled, false);
  unavailable = true;
  await page.time.advance(2000);
  assert.equal(page.find('[data-update-cancel]').disabled, true);
  page.click('cancel');
  assert.equal(page.writes('cancel').length, 0);
  unavailable = false;
  page.window.dispatchEvent(new page.window.Event('online'));
  await settle();
  assert.equal(page.find('[data-update-cancel]').disabled, false);
});

test('the five-minute password proof expires without a reload or stale enabled controls', async (t) => {
  const page = updatesPage(t, { remembered: remembered(), operation: operation() });
  await settle();
  await authorize(page);
  assert.equal(page.find('[data-update-cancel]').disabled, false);
  await page.time.advance(301000);
  assert.equal(page.find('[data-update-cancel]').disabled, true);
  page.click('cancel');
  assert.equal(page.writes('cancel').length, 0);
});

for (const failure of ['login HTML', 'JSON parsing failure']) {
  test(`a returning ${failure} preserves uncertainty and cannot authorize a write`, async (t) => {
    const page = updatesPage(t, { remembered: remembered(), operation: operation(), fetch: (request, normal) => {
      if (!request.url.pathname.endsWith('/status')) return normal(request);
      return response({}, 200, { redirected: failure === 'login HTML',
        headers: { get: () => 'text/html' }, json: async () => { throw new SyntaxError('Unexpected HTML'); } });
    } });
    await settle();
    assert.equal(currentStage(page), 'downloading');
    assert.equal(page.find('[data-update-start]').disabled, true);
    assert.equal(page.find('[data-update-cancel]').disabled, true);
    assert.doesNotMatch(page.text('outcome'), /Update completed/);
    assert.equal(page.writes().length, 0);
  });
}

test('forged browser completion bookmarks never supply success proof', async (t) => {
  const page = updatesPage(t, { remembered: remembered({ phase: 'succeeded', stage: 'verifying', revision: 20 }),
    fetch: (request, normal) => request.url.pathname.endsWith('/status') ? Promise.reject(new TypeError('Host not reachable')) : normal(request) });
  await settle();
  assert.equal(page.find('[data-update-outcome]').dataset.outcome, 'reconciliation_required');
  assert.doesNotMatch(page.text('outcome'), /Update completed/);
  assert.equal(page.find('[data-update-cancel]').disabled, true);
  assert.equal(page.writes().length, 0);
});

test('release notes and authored requirement text render as text with unknown language, never HTML', async (t) => {
  const unsafe = '<img src=x onerror="window.releaseExecuted=true">';
  const page = updatesPage(t, { review: review({ target: { ...review().target, release_notes: unsafe },
    release_requirements: { migration_blocked: false, actions: [{ id: 'test', summary: unsafe, detail: unsafe, phase: 'before_migrate' }] } }) });
  await settle();
  assert.equal(page.find('[data-update-review]').querySelector('img'), null);
  assert.ok(page.text('review').includes(unsafe));
  assert.ok(Array.from(page.find('[data-update-review]').querySelectorAll('[lang=""]')).some((node) => node.textContent === unsafe));
  assert.equal(page.window.releaseExecuted, undefined);
  assert.equal(page.writes().length, 0);
});

function resumePage(t, options = {}) {
  const dom = new JSDOM('<p data-update-resume hidden>Closing does not cancel. <a href="/operator/updates">View progress</a></p>', {
    url: 'https://support.example.test/operator', runScripts: 'outside-only',
  });
  t.after(() => dom.window.close());
  const { window } = dom;
  if (options.remembered) window.localStorage.setItem('wayfindr:update:7', JSON.stringify(options.remembered));
  if (options.otherActor) window.localStorage.setItem('wayfindr:update:8', JSON.stringify(options.otherActor));
  if (options.storageBlocked) Object.defineProperty(window, 'localStorage', { get() { throw new window.DOMException('Blocked', 'SecurityError'); } });
  const requests = [];
  window.fetch = async (url, init) => { requests.push({ url, init });
    if (options.unavailable) throw new TypeError('Network unavailable');
    return response({ schema: 1, snapshot: snapshot(options.operation ?? null) }, options.status ?? 200); };
  const view = fs.readFileSync(path.join(root, 'apps/server/resources/views/components/operator-update-resume.blade.php'), 'utf8');
  const script = view.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace('@json((int) auth()->id())', '7')
    .replace("@json(config('wayfindr.updates.helper_enabled') === true)", options.enabled === false ? 'false' : 'true')
    .replace("@json(route('operator.updates.status'))", JSON.stringify('/operator/updates/status'));
  assert.equal(script.includes('@json'), false, 'all server expressions are resolved in the fixture');
  window.eval(script);
  return { window, requests, banner: window.document.querySelector('[data-update-resume]') };
}

test('returning to the operator console offers a progress link from a bookmark without posting', async (t) => {
  const page = resumePage(t, { remembered: remembered(), unavailable: true });
  await settle();
  assert.equal(page.banner.hidden, false);
  assert.equal(page.banner.querySelector('a').getAttribute('href'), '/operator/updates');
  assert.equal(page.requests.length, 1);
  assert.ok(page.requests.every((request) => !request.init.method || request.init.method === 'GET'));
  assert.doesNotMatch(page.banner.textContent, /completed|succeeded/);
});

test('a returning operator console can discover the active host operation with storage blocked', async (t) => {
  const page = resumePage(t, { storageBlocked: true, operation: operation() });
  await settle();
  assert.equal(page.banner.hidden, false);
  assert.equal(page.requests.length, 1);
  assert.equal(page.requests[0].init.credentials, 'same-origin');
  assert.equal(page.requests[0].init.redirect, 'error');
});

test('operator-console resume does not expose another actor bookmark or assume auth failure is an active run', async (t) => {
  const page = resumePage(t, { otherActor: remembered(), operation: operation(), status: 401 });
  await settle();
  assert.equal(page.banner.hidden, true);
  assert.equal(page.requests.length, 1);
});

test('a deployment without a helper retains manual-only resume links without network work', async (t) => {
  const page = resumePage(t, { remembered: remembered(), enabled: false });
  await settle();
  assert.equal(page.banner.hidden, false);
  assert.equal(page.requests.length, 0);
});

test('translated dynamic review labels retain the document language while authored release text is marked unknown', async (t) => {
  const page = updatesPage(t, { language: 'de', copy: {
    labels: { ...COPY.labels, unknown: 'Unbekannt', unverified: 'Ungeprüft', release_notes: 'Versionshinweise' },
    review: { ...COPY.review, 'action_phase_before-pull': 'Vor dem Herunterladen', action_DO: 'Arbeit ausführen und erneut prüfen.' },
  }, review: review({ target: { ...review().target, platform: null, release_notes: 'Release author supplied these notes.' },
    release_requirements: { migration_blocked: false, actions: [{ id: 'runtime', summary: 'Release author required this work.',
      phase: 'before-pull', disposition: 'DO' }] } }) });
  await settle();
  const container = page.find('[data-update-review]');
  const unknown = Array.from(container.querySelectorAll('dd')).find((node) => node.textContent === 'Unbekannt');
  assert.ok(unknown, 'the target platform is displayed as translated unknown');
  assert.equal(unknown.hasAttribute('lang'), false, 'translated status inherits German from the document');
  assert.match(container.textContent, /Vor dem Herunterladen/);
  assert.match(container.textContent, /Arbeit ausführen/);
  const authored = Array.from(container.querySelectorAll('p,strong')).filter((node) => node.textContent.startsWith('Release author'));
  assert.equal(authored.length, 2);
  assert.ok(authored.every((node) => node.getAttribute('lang') === ''));
  const running = updatesPage(t, { remembered: remembered(), operation: operation(), language: 'de',
    copy: { labels: { ...COPY.labels, unverified: 'Ungeprüft' } } });
  await settle();
  const status = Array.from(running.find('[data-update-details]').querySelectorAll('dd')).find((node) => node.textContent === 'Ungeprüft');
  assert.ok(status, 'the backup status is translated in the progress details');
  assert.equal(status.hasAttribute('lang'), false);
});

for (const [reason, expected] of [['helper_refused:plan_mismatch', 'Die Prüfung stimmt nicht mehr.'], ['private-provider-diagnostic', 'Die Anfrage konnte nicht geprüft werden.']]) {
  test(`dynamic German errors translate ${reason.startsWith('helper_refused') ? 'known' : 'unknown'} reasons without raw diagnostics`, async (t) => {
    const page = updatesPage(t, { language: 'de', copy: { errors: { unknown: 'Die Anfrage konnte nicht geprüft werden.', plan_mismatch: 'Die Prüfung stimmt nicht mehr.' } },
      fetch: (request, normal) => request.url.pathname.endsWith('/candidate') ? response({ schema: 1, reason }, 503) : normal(request) });
    await settle();
    assert.equal(page.text('message'), expected);
    assert.ok(!page.text('message').includes(reason));
    assert.equal(page.writes().length, 0);
  });
}

for (const mismatch of ['request', 'actor']) {
  test(`an uncertain preparation cannot select an active receipt with a different ${mismatch}`, async (t) => {
    const page = updatesPage(t, { fetch: (request, normal) => request.url.pathname.endsWith('/plan')
      ? Promise.reject(new TypeError('Reply lost')) : normal(request) });
    await settle();
    await authorize(page);
    page.click('prepare');
    await settle();
    const original = page.saved().pending.request_id;
    page.setOperation(operation({ operation_id: OTHER_OPERATION, request_id: mismatch === 'request' ? START_REQUEST : original,
      operator: { prepare: { actor: { id: mismatch === 'actor' ? 8 : 7 }, at: NOW / 1000, revision: 1 } } }));
    await page.time.advance(5000);
    assert.equal(page.saved().pending.request_id, original);
    assert.equal(page.saved().operation_id, null, 'a lost request must not become an unrelated active operation');
    assert.equal(page.writes('plan').length, 1);
  });
}

test('lost preparation reconciles its exact actor receipt through one bounded host-history page', async (t) => {
  let requestId = null;
  const unrelated = operation({ operation_id: OTHER_OPERATION, request_id: START_REQUEST,
    operator: { prepare: { actor: { id: 8 }, at: NOW / 1000, revision: 1 } } });
  const page = updatesPage(t, { fetch: (request, normal) => {
    if (request.url.pathname.endsWith('/plan')) {
      requestId = request.body.request_id;
      return Promise.reject(new TypeError('Reply lost'));
    }
    if (requestId && request.url.pathname.endsWith('/status')) {
      const exact = request.url.searchParams.get('operation_id') === OPERATION;
      return response({ schema: 1, snapshot: snapshot(exact ? prepared({ request_id: requestId }) : unrelated) });
    }
    if (requestId && request.url.pathname.endsWith('/history')) return response({ schema: 1, snapshot: {
      schema: 1, installation_id: INSTALLATION, revision: 8, active_operation: OTHER_OPERATION,
      cursor: 0, next_cursor: 2, has_more: false, operations: [unrelated, prepared({ request_id: requestId })],
    } });
    return normal(request);
  } });
  await settle();
  await authorize(page);
  page.click('prepare');
  await settle();
  await page.time.advance(5000);
  assert.equal(page.saved().operation_id, OPERATION);
  assert.equal(page.saved().pending, null);
  assert.equal(page.writes('plan').length, 1, 'history reconciliation does not prepare again');
  assert.equal(page.requests.filter((request) => request.url.pathname.endsWith('/history') && request.url.searchParams.get('limit') === '50').length, 1);
  assert.ok(page.requests.some((request) => request.url.pathname.endsWith('/status') && request.url.searchParams.get('operation_id') === OPERATION));
});

for (const action of ['start', 'cancel']) {
  test(`a mismatched saved ${action} intent cannot retry against a different operation or plan`, async (t) => {
    const page = updatesPage(t, { remembered: remembered({ pending: {
      action, request_id: 'eeeeeeee-ffff-4000-8000-111111111111', operation_id: OTHER_OPERATION, plan_id: 'f'.repeat(64),
    } }), operation: operation() });
    await settle();
    await authorize(page);
    page.click('retry');
    await settle();
    assert.equal(page.writes(action).length, 0, 'saved intent must match the displayed operation and plan');
  });
}

test('an unadmitted cancellation retry is disabled after the host records migration intent', async (t) => {
  const page = updatesPage(t, { remembered: remembered({ pending: {
    action: 'cancel', request_id: 'eeeeeeee-ffff-4000-8000-111111111111', operation_id: OPERATION, plan_id: PLAN,
  } }), operation: operation({ phase: 'applying', checkpoint: 'migration_intent', revision: 8, mutation_started: true,
    apply: { phase: 'applying', migration_started: true } }) });
  await settle();
  await authorize(page);
  assert.equal(page.find('[data-update-cancel]').disabled, true);
  assert.equal(page.find('[data-update-retry]').disabled, true, 'Retry obeys the same confirmed migration boundary');
  page.click('retry');
  assert.equal(page.writes('cancel').length, 0);
});

test('returning from browser back-forward cache resumes read-only status observation', async (t) => {
  const page = updatesPage(t, { remembered: remembered(), operation: operation() });
  await settle();
  page.window.dispatchEvent(new page.window.PageTransitionEvent('pagehide', { persisted: true }));
  const before = page.requests.filter((request) => request.url.pathname.endsWith('/status')).length;
  page.window.dispatchEvent(new page.window.PageTransitionEvent('pageshow', { persisted: true }));
  await page.time.advance(5000);
  assert.ok(page.requests.filter((request) => request.url.pathname.endsWith('/status')).length > before,
    'a retained page re-reads the host after navigation back');
  assert.equal(page.writes().length, 0);
});

test('an explicit maintenance refusal locks an uncertain start retry until status returns', async (t) => {
  const page = updatesPage(t, { remembered: remembered({ revision: 3, phase: 'blocked', stage: 'preparing' }), operation: prepared(),
    fetch: (request, normal) => request.url.pathname.endsWith('/start')
      ? response({ schema: 1, reason: 'managed_update_hold' }, 503) : normal(request) });
  await settle();
  await authorize(page);
  const confirmation = page.find('[data-update-confirm]');
  confirmation.checked = true;
  confirmation.dispatchEvent(new page.window.Event('change', { bubbles: true }));
  page.click('start');
  await settle();
  assert.equal(page.find('[data-update-retry]').disabled, true, 'maintenance refusal means admission is currently unavailable');
  page.click('retry');
  assert.equal(page.writes('start').length, 1);
});

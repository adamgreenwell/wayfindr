const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const test = require('node:test');

const root = path.resolve(__dirname, '../../../..');
const { JSDOM } = createRequire(path.join(root, 'packages/widget-js/package.json'))('jsdom');
const view = fs.readFileSync(path.join(root, 'apps/server/resources/views/agent/account/ai-assist.blade.php'), 'utf8');
const script = process.env.AI_ASSIST_SCRIPT_COPY
  ? fs.readFileSync(process.env.AI_ASSIST_SCRIPT_COPY, 'utf8')
  : view.match(/<script>([\s\S]*?)<\/script>/)[1];

function page(t, source = 'account', state = 'ready') {
  const dom = new JSDOM(`
    <div data-ai-assist>
      <form action="/propose" method="post" data-ai-connection-form>
        <input name="_token" value="csrf-fixture" type="hidden">
        <input name="expected_revision" value="draft-revision" type="hidden">
        <input name="editor_token" value="editor-snapshot-token" type="hidden">
        <input type="radio" name="source" value="installation" ${source === 'installation' ? 'checked' : ''}>
        <input type="radio" name="source" value="account" ${source === 'account' ? 'checked' : ''}>
        <input name="name" value="Support connection">
        <fieldset data-ai-own-fields>
          <select name="provider" data-ai-provider><option value="openai">OpenAI</option><option value="openrouter">OpenRouter</option></select>
          <input name="model" value="fixture-model">
          <input name="endpoint" value="">
          <div data-ai-router-fields><input name="openrouter_provider" value="fixture-upstream"></div>
          <input name="api_key" type="password" value="" autocomplete="new-password">
          <input name="clear_api_key" type="checkbox" value="1" data-ai-clear-key>
        </fieldset>
        <div data-ai-test-status data-state="${state}" data-stale-label="Settings changed" data-stale-help="Test again" data-preparing-label="Checking connection…" data-preparing-help="Using sample text">
          <strong data-ai-state-label>Ready to save</strong><p data-ai-state-help>Exact draft tested</p>
        </div>
        <button type="submit" data-ai-action="propose">Keep draft</button>
        <button type="submit" data-ai-action="discover" formaction="/discover">Discover</button>
        <button type="submit" data-ai-action="test" formaction="/test" ${state === 'preparing' ? 'disabled' : ''}>Test</button>
        <button type="submit" data-ai-action="activate" formaction="/activate" ${state === 'preparing' ? 'disabled' : ''}>Save</button>
      </form>
      <form action="/disconnect" method="post"><button type="submit">Disconnect</button></form>
    </div>`, { url: 'https://support.example.test/dashboard/account/ai-assist', runScripts: 'outside-only' });
  t.after(() => dom.window.close());
  const { window } = dom;
  const timers = [];
  window.setTimeout = (callback) => timers.push(callback);
  window.fetch = () => { throw new Error('Native forms must not dispatch fetch'); };
  for (const storage of [window.localStorage, window.sessionStorage]) {
    storage.setItem('unrelated', 'unchanged');
  }
  window.eval(script);
  return {
    window,
    form: window.document.querySelector('[data-ai-connection-form]'),
    status: window.document.querySelector('[data-ai-test-status]'),
    save: window.document.querySelector('[data-ai-action="activate"]'),
    flush: () => { while (timers.length) timers.shift()(); },
  };
}

function edit(window, control, value, type = 'input') {
  control.value = value;
  control.dispatchEvent(new window.Event(type, { bubbles: true }));
}

function submit(window, form, button) {
  const event = new window.SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: button });
  form.dispatchEvent(event);
  return event;
}

test('editing processing configuration invalidates Save even if the earlier value is restored', (t) => {
  const { window, form, status, save } = page(t);
  for (const [name, changed] of [['provider', 'openrouter'], ['model', 'other-model'], ['endpoint', 'https://other.example.test/v1'], ['api_key', 'synthetic-replacement']]) {
    save.disabled = false;
    edit(window, form.elements[name], changed);
    assert.equal(save.disabled, true, name + ' must invalidate the tested draft');
    assert.equal(status.dataset.state, 'stale');
    assert.equal(status.querySelector('[data-ai-state-label]').textContent, 'Settings changed');
  }
  edit(window, form.elements.model, 'fixture-model');
  assert.equal(save.disabled, true, 'Restoring text cannot recreate server test authority');
});

test('change-only provider and source events also invalidate Save and omit irrelevant credentials from POST', (t) => {
  const { window, form, status, save } = page(t);
  edit(window, form.elements.provider, 'openrouter', 'change');
  assert.equal(save.disabled, true, 'A provider change event must invalidate the tested draft');
  assert.equal(form.querySelector('[data-ai-router-fields]').hidden, false);
  assert.equal(new window.FormData(form).get('openrouter_provider'), 'fixture-upstream');

  edit(window, form.elements.provider, 'openai', 'change');
  assert.equal(form.querySelector('[data-ai-router-fields]').hidden, true);
  assert.equal(new window.FormData(form).has('openrouter_provider'), false);

  edit(window, form.elements.api_key, 'synthetic-replacement');
  form.querySelector('[name="source"][value="installation"]').checked = true;
  form.querySelector('[name="source"][value="installation"]').dispatchEvent(new window.Event('change', { bubbles: true }));
  assert.equal(status.dataset.state, 'stale');
  const body = new window.FormData(form);
  assert.equal(body.get('source'), 'installation');
  assert.equal(body.has('api_key'), false, 'A replacement key must not accompany installation selection');
  assert.equal(body.has('endpoint'), false);
  assert.equal(form.querySelector('[data-ai-action="discover"]').hidden, true);
});

test('installation selection initially hides account fields without removing name or revision from native POST', (t) => {
  const { window, form } = page(t, 'installation');
  assert.equal(form.querySelector('[data-ai-own-fields]').hidden, true);
  const body = new window.FormData(form);
  assert.equal(body.get('name'), 'Support connection');
  assert.equal(body.get('expected_revision'), 'draft-revision');
  assert.equal(body.get('editor_token'), 'editor-snapshot-token');
  assert.equal(body.has('model'), false);
});

test('clear and replacement are mutually exclusive without copying a secret into status, storage, or history', (t) => {
  const { window, form, status } = page(t);
  const key = form.elements.api_key;
  const clear = form.elements.clear_api_key;
  edit(window, key, 'synthetic-key-not-a-real-secret');
  clear.checked = true;
  clear.dispatchEvent(new window.Event('change', { bubbles: true }));
  assert.equal(key.value, '', 'Clear must erase the browser replacement');
  assert.equal(clear.checked, true);
  edit(window, key, 'synthetic-new-key');
  assert.equal(clear.checked, false, 'Typing a replacement must cancel Clear');
  assert.equal(status.textContent.includes('synthetic-new-key'), false);
  assert.equal(window.location.href, 'https://support.example.test/dashboard/account/ai-assist');
  for (const storage of [window.localStorage, window.sessionStorage]) {
    assert.equal(storage.length, 1);
    assert.equal(storage.getItem('unrelated'), 'unchanged');
  }
});

test('native test submission shows Preparing and preserves submitter intent and all successful controls before locking buttons', (t) => {
  const { window, form, status, flush } = page(t);
  edit(window, form.elements.api_key, 'synthetic-key');
  const button = form.querySelector('[data-ai-action="test"]');
  const event = submit(window, form, button);
  assert.equal(event.defaultPrevented, false, 'The browser must perform the native POST');
  assert.equal(status.dataset.state, 'preparing');
  assert.equal(status.querySelector('[data-ai-state-help]').textContent, 'Using sample text');
  assert.equal(form.getAttribute('aria-busy'), 'true');
  assert.equal(button.disabled, false, 'Do not disable the submitter before the browser captures its route');
  assert.equal(button.getAttribute('formaction'), '/test');
  assert.equal(new window.FormData(form).get('api_key'), 'synthetic-key');
  assert.equal(new window.FormData(form).get('editor_token'), 'editor-snapshot-token');
  const otherForm = window.document.querySelector('form[action="/disconnect"]');
  assert.equal(submit(window, otherForm, otherForm.querySelector('button')).defaultPrevented, true, 'A pending POST must block duplicate page actions');
  flush();
  assert.equal(button.disabled, true);
  assert.equal(form.elements.api_key.disabled, false, 'Do not drop request fields while capturing POST');
  assert.equal(submit(window, otherForm, otherForm.querySelector('button')).defaultPrevented, true, 'A disabled page action must remain blocked');
});

test('a disabled Save cannot submit, and choosing a discovered model invalidates the same exact revision', (t) => {
  const { window, form, save } = page(t);
  edit(window, form.elements.model, 'discovered-model');
  assert.equal(form.elements.expected_revision.value, 'draft-revision');
  assert.equal(form.elements.editor_token.value, 'editor-snapshot-token', 'Editing cannot regenerate snapshot authority');
  assert.equal(submit(window, form, save).defaultPrevented, true);
  assert.equal(submit(window, form, form.querySelector('[data-ai-action="test"]')).defaultPrevented, false);
});


test('a name-only edit preserves Ready and exact tested draft authority', (t) => {
  const { window, form, status, save } = page(t);
  edit(window, form.elements.name, 'Renamed support connection');
  assert.equal(save.disabled, false, 'A display name does not change processing revision');
  assert.equal(status.dataset.state, 'ready');
  assert.equal(new window.FormData(form).get('name'), 'Renamed support connection');
  assert.equal(new window.FormData(form).get('editor_token'), 'editor-snapshot-token');
  assert.equal(submit(window, form, save).defaultPrevented, false);
});

test('a reloaded Preparing page blocks the same proposal until processing settings change', (t) => {
  const { window, form, status, save } = page(t, 'account', 'preparing');
  const testButton = form.querySelector('[data-ai-action="test"]');
  assert.equal(testButton.disabled, true, 'A reloaded running test must remain disabled');
  assert.equal(submit(window, form, testButton).defaultPrevented, true);
  edit(window, form.elements.name, 'Cosmetic rename');
  assert.equal(testButton.disabled, true, 'Renaming does not supersede the running test');
  edit(window, form.elements.model, ' fixture-model ');
  assert.equal(testButton.disabled, true, 'Whitespace normalization must not unlock the same running proposal');
  edit(window, form.elements.api_key, '   ');
  assert.equal(testButton.disabled, true, 'A blank replacement key must not unlock the same running proposal');
  edit(window, form.elements.model, 'corrected-model');
  assert.equal(testButton.disabled, false, 'A changed processing draft can be tested');
  assert.equal(status.dataset.state, 'stale');
  assert.equal(save.disabled, true);
  edit(window, form.elements.model, 'fixture-model');
  assert.equal(testButton.disabled, true, 'Restoring the running proposal must restore its test fence');
  assert.equal(status.dataset.state, 'preparing');
  edit(window, form.elements.model, 'corrected-model');
  assert.equal(submit(window, form, testButton).defaultPrevented, false);
});

test('restoring a discovery GET from history clears typed keys and only reloads the GET document', (t) => {
  const { runInNewContext } = require('node:vm');
  const { window, form } = page(t);
  const callbacks = new Map();
  let reloads = 0;
  let requests = 0;
  const documentUrl = window.location.href;
  const browserWindow = {
    addEventListener: (name, callback) => callbacks.set(name, callback),
    location: { href: documentUrl, reload: () => { reloads++; } },
    setTimeout: window.setTimeout,
    fetch: () => { requests++; throw new Error('History must never dispatch discovery'); },
  };
  runInNewContext(script, { window: browserWindow, document: window.document, Intl, JSON });
  form.elements.api_key.value = 'synthetic-replacement-not-persisted';
  callbacks.get('pageshow')({ persisted: false });
  assert.equal(reloads, 0);
  callbacks.get('pageshow')({ persisted: true });
  assert.equal(form.elements.api_key.value, '');
  assert.equal(reloads, 1);
  assert.equal(browserWindow.location.href, documentUrl);
  assert.equal(requests, 0);
});

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const test = require('node:test');
const root = path.resolve(__dirname, '../../../..');
const { JSDOM } = createRequire(path.join(root, 'packages/widget-js/package.json'))('jsdom');
const generation = '2f07b4b0-6c86-49c9-aef7-b63498df8395';

function page(t, kind = 'reply-draft') {
  const file = process.env.COPILOT_USE_SCRIPT_COPY || path.join(root, `apps/server/resources/views/agent/conversations/partials/copilot-${kind}-script.blade.php`);
  const script = fs.readFileSync(file, 'utf8').replace(/<\/?script>/g, '').replace(/@json\(__\('([^']+)'\)\)/g, (_, key) => JSON.stringify(key));
  const button = kind === 'knowledge-suggestion' ? 'knowledge-use' : `${kind}-use`;
  const content = kind === 'knowledge-suggestion'
    ? `<article data-copilot-knowledge-article data-article-id="42" data-article-revision="${'a'.repeat(64)}"><p data-copilot-knowledge-snippet>Cached snippet.</p><button data-copilot-${button}>Use</button></article>`
    : `<p data-copilot-${kind}-content>Cached draft.</p><button data-copilot-${button}>Use</button>`;
  const dom = new JSDOM(`<section data-reply-shell>
    <textarea data-reply-body></textarea><select data-template-picker><option value="">None</option></select><p data-reply-status></p>
    <div data-copilot-${kind} data-state="ready" data-generation="${generation}" data-policy-revision="policy-one" data-status-url="/status" data-use-url="/use">
      <div data-copilot-${kind}-stale hidden></div>${content}<p data-copilot-knowledge-status></p><p data-copilot-ticket-suggestion-status></p>
    </div>
    <form data-ticket-creation-form><input data-ticket-subject value=""><select data-ticket-priority><option value="normal">Normal</option><option value="high">High</option></select>
      <input type="checkbox" data-ticket-label-id value="7"><input type="checkbox" data-ticket-label-id value="8">
    </form>
  </section>`, { url: 'https://support.example.test/dashboard/conversations/WF-TEST', runScripts: 'outside-only' });
  t.after(() => dom.window.close());
  const { window } = dom;
  const timers = [];
  const requests = [];
  window.setTimeout = (callback, delay) => { timers.push({ callback, delay }); };
  window.fetch = (url, options) => new Promise((resolve) => requests.push({ url: String(url), options, resolve }));
  window.eval(script);
  return {
    window, timers, requests,
    panel: window.document.querySelector(`[data-copilot-${kind}]`),
    button: window.document.querySelector(`[data-copilot-${button}]`),
    body: window.document.querySelector('[data-reply-body]'),
    subject: window.document.querySelector('[data-ticket-subject]'),
    priority: window.document.querySelector('[data-ticket-priority]'),
    form: window.document.querySelector('[data-ticket-creation-form]'),
  };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));
function edit(fixture, control, value, type = 'input') {
  control.value = value;
  control.dispatchEvent(new fixture.window.Event(type, { bubbles: true }));
}
function respond(fixture, payload, status = 200) {
  fixture.requests.at(-1).resolve({ ok: status >= 200 && status < 300, status, json: async () => payload, text: async () => payload });
}
const textPayload = { generation, policy_revision: 'policy-one', text: 'Fresh server-approved text.' };
const ticketPayload = { generation, policy_revision: 'policy-one', title: 'Fresh server-approved title', priority: 'high', label_ids: [8] };

for (const kind of ['reply-draft', 'knowledge-suggestion']) {
  test(`${kind} inserts the fresh server payload rather than cached DOM text`, async (t) => {
    const f = page(t, kind);
    f.button.click();
    assert.equal(f.requests.length, 1);
    assert.match(f.requests[0].url, /generation=2f07/);
    assert.equal(f.requests[0].options.headers.Accept, 'application/json');
    assert.equal(f.requests[0].options.cache, 'no-store');
    if (kind === 'knowledge-suggestion') {
      assert.match(f.requests[0].url, /article_id=42/);
      assert.match(f.requests[0].url, /article_revision=aaaaaaaa/);
    }
    respond(f, textPayload); await flush();
    assert.equal(f.body.value, 'Fresh server-approved text.');
  });

  test(`${kind} preserves edits, including typing then deleting while Use waits`, async (t) => {
    const f = page(t, kind);
    f.button.click();
    edit(f, f.body, 'My own text.');
    edit(f, f.body, '');
    respond(f, textPayload); await flush();
    assert.equal(f.body.value, '', 'An empty composer after user edits is still user-owned.');
    const status = f.window.document.querySelector(kind === 'reply-draft' ? '[data-reply-status]' : '[data-copilot-knowledge-status]');
    assert.match(status.textContent, /use_blocked/);
  });

  test(`${kind} refuses a detached, replaced, or stale policy result`, async (t) => {
    const f = page(t, kind);
    f.button.click();
    f.panel.dataset.policyRevision = 'policy-two';
    respond(f, textPayload); await flush();
    assert.equal(f.body.value, '');
    f.panel.dataset.policyRevision = 'policy-one';
    f.button.click();
    respond(f, { ...textPayload, generation: 'another-generation' }); await flush();
    assert.equal(f.body.value, '');
    f.button.click();
    f.panel.remove();
    respond(f, textPayload); await flush();
    assert.equal(f.body.value, '');
  });
}

test('ticket Use applies only fresh server fields and current label IDs to the untouched form', async (t) => {
  const f = page(t, 'ticket-suggestion');
  f.button.click();
  respond(f, ticketPayload); await flush();
  assert.equal(f.subject.value, 'Fresh server-approved title');
  assert.equal(f.priority.value, 'high');
  assert.equal(f.form.querySelector('[value="7"]').checked, false);
  assert.equal(f.form.querySelector('[value="8"]').checked, true);
  assert.equal(f.form.dataset.ticketSuggestionDirty, 'true');
});

test('ticket Use rechecks user edits after the server response, including restored values', async (t) => {
  const f = page(t, 'ticket-suggestion');
  f.button.click();
  edit(f, f.subject, 'My title.');
  edit(f, f.subject, '');
  respond(f, ticketPayload); await flush();
  assert.equal(f.subject.value, '', 'Do not fill a form the agent edited during the request.');
  assert.equal(f.priority.value, 'normal');
  assert.equal(f.form.querySelector('[value="8"]').checked, false);
});

for (const kind of ['summary', 'reply-draft', 'knowledge-suggestion', 'ticket-suggestion']) {
  test(`${kind} continues read-only polling after Ready and withdraws output on policy denial`, async (t) => {
    const f = page(t, kind);
    edit(f, f.body, 'Human composer content.');
    edit(f, f.subject, 'Human ticket title.');
    f.timers.shift().callback();
    assert.equal(f.requests.length, 1, 'Ready results must still be reauthorized.');
    assert.equal(f.requests[0].url, '/status');
    assert.equal(f.requests[0].options.headers.Accept, 'text/html');
    respond(f, '', 404); await flush();
    assert.equal(f.panel.isConnected, false, 'Revoked output must disappear.');
    assert.equal(f.body.value, 'Human composer content.');
    assert.equal(f.subject.value, 'Human ticket title.');
  });
}

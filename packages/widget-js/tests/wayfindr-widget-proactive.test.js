const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

function jsonResponse(status, payload) {
  return { ok: status >= 200 && status < 300, status, json: async () => payload };
}

function memoryStorage(seed) {
  const values = new Map(Object.entries(seed || {}));

  return {
    getItem: (key) => (values.has(key) ? values.get(key) : null),
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
  };
}

async function settle() {
  for (let i = 0; i < 8; i++) {
    await new Promise((resolve) => setImmediate(resolve));
    await new Promise((resolve) => setTimeout(resolve, 0));
  }
}

function proactiveWidget({ href, outcomeStatuses, referrer, rules, storage } = {}) {
  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: href || 'https://shop.example.test/pricing?campaign=private#offer',
    referrer: referrer || 'https://search.example/results?q=private',
  });
  const calls = [];
  const deliveryId = '8ecfd18a-5bec-4a4a-b2d1-40b9b5c93ed8';
  const activeStorage = storage === undefined ? memoryStorage({
    'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
  }) : storage;
  const configuredRules = rules || [{
    id: '570c37cb-a49e-4185-ab7f-d94e676e7b33',
    message: 'Questions about plans? <img src=x onerror=alert(1)>',
    url_contains: '/pricing',
    referrer_contains: 'search.example',
    delay_seconds: 0,
    minimum_visit_count: 1,
    requires_available_agent: false,
    frequency_cap_minutes: 60,
    dismissal_snooze_minutes: 1440,
  }];

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages: [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: activeStorage,
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: 0,
    fetch: async (url, init) => {
      const method = (init && init.method) || 'GET';
      const body = init && init.body ? JSON.parse(init.body) : null;
      calls.push({ url, method, body });

      if (url.includes('/api/widget/appearance')) {
        return jsonResponse(200, {
          data: {
            appearance: { position: 'right' },
            locale: 'en',
            presence: { reports: true, every: 45, page_urls: true, retention_days: 30 },
            proactive_messages: configuredRules,
          },
        });
      }

      if (url.endsWith('/api/widget/presence')) {
        return jsonResponse(202, { data: { reports: true } });
      }

      if (url.includes('/api/widget/proactive-messages/') && url.endsWith('/authorize')) {
        return jsonResponse(201, {
          data: {
            authorized: true,
            delivery_id: deliveryId,
            message: configuredRules[0].message,
            expires_at: new Date(Date.now() + 60_000).toISOString(),
          },
        });
      }

      if (url.includes('/api/widget/proactive-messages/') && url.endsWith('/outcomes')) {
        const outcomeStatus = (outcomeStatuses && outcomeStatuses[body.outcome]) || 202;

        return jsonResponse(outcomeStatus, { data: { recorded: outcomeStatus < 400 } });
      }

      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: {
            site: {
              public_key: 'site_public_shop',
              name: 'Shop support',
              settings: {},
              appearance: { position: 'right' },
              presence: { reports: true, every: 45, page_urls: true, retention_days: 30 },
            },
            visitor: { anonymous_id: 'anon-shop', token: 'visitor-token-shop' },
          },
        });
      }

      if (url.endsWith('/api/conversations')) {
        return jsonResponse(201, { data: { support_code: 'WF-PROACTIVE', status: 'open' } });
      }

      if (url.includes('/api/conversations/WF-PROACTIVE/messages') && method === 'POST') {
        return jsonResponse(201, {
          data: {
            conversation: { support_code: 'WF-PROACTIVE', status: 'open' },
            message: {
              id: 2,
              sender: { kind: 'visitor', name: 'Visitor' },
              type: 'text',
              body: body.body,
              attachments: [],
              created_at: new Date().toISOString(),
            },
          },
        });
      }

      if (url.includes('/api/conversations/WF-PROACTIVE/messages')) {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-PROACTIVE', status: 'open', awaiting_rating: false },
            messages: [],
            agent_typing: { state: 'idle' },
            visitor_read: {},
          },
        });
      }

      if (url.includes('/api/conversations/WF-PROACTIVE/cobrowse')) {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-PROACTIVE', status: 'open' },
            cobrowse: { status: 'unavailable', consent: 'unavailable', requested_by: null },
          },
        });
      }

      return jsonResponse(200, { data: {} });
    },
  });

  return { calls, deliveryId, dom, storage: activeStorage, widget };
}

function callsEnding(calls, suffix) {
  return calls.filter((call) => call.url.endsWith(suffix));
}

test('matches browsing conditions locally and records a visible plain-text invitation', async () => {
  const { calls, widget } = proactiveWidget();

  await settle();

  const authorization = callsEnding(calls, '/authorize');
  const outcomes = callsEnding(calls, '/outcomes');
  const invitation = widget.root.querySelector('.wayfindr-widget__proactive');

  assert.equal(authorization.length, 1);
  assert.deepEqual(Object.keys(authorization[0].body).sort(), ['anonymous_id', 'claim_key', 'site_public_key']);
  assert.equal(invitation.hidden, false);
  assert.match(invitation.textContent, /Questions about plans/);
  assert.equal(invitation.querySelector('img'), null, 'visitor-facing copy was interpreted as markup');
  assert.equal(invitation.querySelector('.wayfindr-widget__proactive-copy').getAttribute('aria-live'), 'polite');
  assert.deepEqual(outcomes.map((call) => call.body.outcome), ['shown']);
  assert.equal(callsEnding(calls, '/api/widget/bootstrap').length, 0, 'showing an invitation made contact');
  assert.equal(callsEnding(calls, '/api/conversations').length, 0, 'showing an invitation created an empty conversation');

  widget.destroy();
});

test('never sends page or referrer values and ignores query-string-only matches', async () => {
  const { calls, widget } = proactiveWidget({
    rules: [{
      id: '570c37cb-a49e-4185-ab7f-d94e676e7b33',
      message: 'This must stay quiet.',
      url_contains: 'campaign=private',
      referrer_contains: null,
      delay_seconds: 0,
      minimum_visit_count: 1,
      frequency_cap_minutes: 60,
      dismissal_snooze_minutes: 1440,
    }],
  });

  await settle();

  assert.equal(callsEnding(calls, '/authorize').length, 0);
  assert.equal(widget.root.querySelector('.wayfindr-widget__proactive').hidden, true);

  widget.destroy();
});

test('dismissal is reported and suppresses a later page load from the same browser', async () => {
  const storage = memoryStorage({ 'wayfindr:site_public_shop:anonymous-id': 'anon-shop' });
  const first = proactiveWidget({ storage });

  await settle();
  first.widget.root.querySelector('.wayfindr-widget__proactive-dismiss').click();
  await settle();

  assert.deepEqual(callsEnding(first.calls, '/outcomes').map((call) => call.body.outcome), ['shown', 'dismissed']);
  assert.equal(first.widget.root.querySelector('.wayfindr-widget__proactive').hidden, true);
  first.widget.destroy();

  const second = proactiveWidget({ storage });
  await settle();

  assert.equal(callsEnding(second.calls, '/authorize').length, 0, 'a remembered dismissal was ignored');
  second.widget.destroy();
});

test('declining presence immediately withdraws an active invitation', async () => {
  const { calls, widget } = proactiveWidget();

  await settle();
  assert.equal(widget.root.querySelector('.wayfindr-widget__proactive').hidden, false);

  widget.root.querySelector('.wayfindr-widget__presence-decline').click();
  await settle();

  assert.equal(widget.root.querySelector('.wayfindr-widget__proactive').hidden, true);
  assert.deepEqual(callsEnding(calls, '/outcomes').map((call) => call.body.outcome), ['shown']);

  widget.destroy();
});

test('engagement opens the ordinary widget and carries the delivery into the first send', async () => {
  const { calls, deliveryId, dom, widget } = proactiveWidget();

  await settle();
  widget.root.querySelector('.wayfindr-widget__proactive-open').click();
  await settle();

  assert.equal(widget.root.querySelector('.wayfindr-widget__panel').hidden, false);
  assert.match(widget.root.querySelector('.wayfindr-widget__timeline').textContent, /Questions about plans/);
  assert.deepEqual(callsEnding(calls, '/outcomes').map((call) => call.body.outcome), ['shown', 'engaged']);

  const textarea = widget.root.querySelector('.wayfindr-widget__textarea');
  textarea.value = 'Yes please, which plan fits us?';
  textarea.dispatchEvent(new dom.window.KeyboardEvent('keydown', {
    key: 'Enter',
    bubbles: true,
    cancelable: true,
  }));
  await settle();

  const start = callsEnding(calls, '/api/conversations');

  assert.equal(start.length, 1);
  assert.equal(start[0].body.proactive_message_delivery_id, deliveryId);
  assert.equal(start[0].body.subject, 'Yes please, which plan fits us?');

  widget.destroy();
});

test('a rejected engagement never paints an opener the server did not accept', async () => {
  const { calls, dom, widget } = proactiveWidget({ outcomeStatuses: { engaged: 404 } });

  await settle();
  widget.root.querySelector('.wayfindr-widget__proactive-open').click();
  await settle();

  assert.equal(widget.root.querySelector('.wayfindr-widget__panel').hidden, false);
  assert.doesNotMatch(widget.root.querySelector('.wayfindr-widget__timeline').textContent, /Questions about plans/);

  const textarea = widget.root.querySelector('.wayfindr-widget__textarea');
  textarea.value = 'I can still ask for help normally.';
  textarea.dispatchEvent(new dom.window.KeyboardEvent('keydown', {
    key: 'Enter',
    bubbles: true,
    cancelable: true,
  }));
  await settle();

  const start = callsEnding(calls, '/api/conversations');

  assert.equal(start.length, 1);
  assert.equal(Object.hasOwn(start[0].body, 'proactive_message_delivery_id'), false);

  widget.destroy();
});

test('proactive messaging fails closed when durable browser storage is unavailable', async () => {
  const { calls, widget } = proactiveWidget({ storage: null });

  await settle();

  assert.equal(callsEnding(calls, '/authorize').length, 0);
  assert.equal(widget.root.querySelector('.wayfindr-widget__proactive').hidden, true);

  widget.destroy();
});

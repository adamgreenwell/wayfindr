const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

function jsonResponse(status, payload) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => payload,
    text: async () => JSON.stringify(payload),
  };
}

async function settle() {
  await new Promise((resolve) => setImmediate(resolve));
  await new Promise((resolve) => setImmediate(resolve));
}

function memoryStorage() {
  const store = new Map();

  return {
    getItem: (key) => (store.has(key) ? store.get(key) : null),
    setItem: (key, value) => store.set(key, String(value)),
    removeItem: (key) => store.delete(key),
  };
}

// Every request the composer makes succeeds, so any failure the visitor is
// shown can only have come from the widget's own post-send work.
function healthyFetch(calls) {
  return async (url, options) => {
    calls.push({ url, method: (options && options.method) || 'GET' });

    if (url.endsWith('/api/widget/bootstrap')) {
      return jsonResponse(200, {
        data: {
          site: { public_key: 'site_public_docs', settings: {} },
          visitor: { anonymous_id: 'anon-docs', token: 'visitor-token-docs' },
        },
      });
    }

    if (url.endsWith('/api/conversations')) {
      return jsonResponse(201, { data: { support_code: 'WF-NEW', status: 'open' } });
    }

    if (url.includes('/cobrowse')) {
      return jsonResponse(200, { data: { cobrowse: { state: 'unavailable' } } });
    }

    return jsonResponse(201, {
      data: {
        conversation: { support_code: 'WF-NEW', status: 'open' },
        message: {
          id: 1,
          sender: { kind: 'visitor' },
          type: 'text',
          body: 'hello',
          attachments: [],
          created_at: '2026-08-14T10:00:00.000000Z',
        },
        messages: [],
      },
    });
  };
}

function widgetWithBrokenRealtime(dom, calls) {
  return Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-docs',
    storage: memoryStorage(),
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 5000,
    // The transport throwing is the case that broke: a null return was always
    // handled, a throw was not.
    realtime: {
      subscribe: () => {
        throw new Error('realtime transport exploded');
      },
    },
    fetch: healthyFetch(calls),
  });
}

async function sendFirstMessage(dom, widget) {
  widget.open();
  widget.root.querySelector('.wayfindr-widget__textarea').value = 'hello';
  widget.root
    .querySelector('.wayfindr-widget__form')
    .dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
  await settle();
}

test('a realtime transport that throws does not report a delivered message as failed', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });
  const calls = [];
  const widget = widgetWithBrokenRealtime(dom, calls);

  await sendFirstMessage(dom, widget);

  const sent = calls.filter((call) => call.url.includes('/messages') && call.method === 'POST');
  assert.equal(sent.length, 1, 'the message is posted exactly once');

  // The server accepted it, so the visitor must not be told to send again --
  // telling them to is what produced duplicate messages in the field.
  const status = widget.root.querySelector('.wayfindr-widget__status').textContent;
  assert.match(status, /Message sent/, `expected a success status, got: ${status}`);
  assert.doesNotMatch(status, /could not be sent/);
});

test('a realtime transport that throws still leaves polling scheduled', async (t) => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });
  const calls = [];

  // Enabled BEFORE the widget exists: mock timers do not adopt a setTimeout
  // that was already scheduled against the real one.
  t.mock.timers.enable({ apis: ['setTimeout'] });

  const widget = widgetWithBrokenRealtime(dom, calls);

  await sendFirstMessage(dom, widget);

  const before = calls.filter((call) => call.url.includes('/messages') && call.method === 'GET').length;

  // Polling is the fallback FOR realtime being unavailable. Scheduling it
  // after an unguarded realtime call meant a throw there removed it, leaving
  // the visitor with no route at all for an agent's reply.
  t.mock.timers.tick(6000);
  await settle();

  const after = calls.filter((call) => call.url.includes('/messages') && call.method === 'GET').length;

  assert.ok(after > before, 'the message poll runs even though realtime threw');
});

test('a genuine send failure is still reported to the visitor', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });
  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-docs',
    storage: memoryStorage(),
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: async (url) => {
      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: {
            site: { public_key: 'site_public_docs', settings: {} },
            visitor: { anonymous_id: 'anon-docs', token: 'visitor-token-docs' },
          },
        });
      }

      if (url.endsWith('/api/conversations')) {
        return jsonResponse(201, { data: { support_code: 'WF-NEW', status: 'open' } });
      }

      return jsonResponse(500, { message: 'nope' });
    },
  });

  await sendFirstMessage(dom, widget);

  const status = widget.root.querySelector('.wayfindr-widget__status').textContent;
  assert.match(status, /could not be sent/, 'a real rejection still tells the visitor to retry');
});

// NOTE on what is deliberately NOT tested here.
//
// The narrowed catch in the composer -- only a failed sendMessage may tell the
// visitor the send failed -- has no test, because no synthetic post-send
// failure could be constructed. Null bodies, missing fields and a
// non-iterable attachments value are all handled without throwing, and the
// one throw seen in the field (a realtime transport blowing up) is now caught
// by activateConversation before the composer sees it.
//
// A test that passes with the fix reverted is worse than no test: it implies
// coverage it does not have. So the narrowed catch stands as defence in depth
// against a class of fault, with this note in place of a green assertion.

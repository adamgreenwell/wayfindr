const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

// A visitor who never opened the chat has no token, so this endpoint is public
// and everything about it is decided defensively: the operator opts in, the
// visitor can decline, a decline we cannot REMEMBER means we do not report at
// all, and the page address is stripped before it goes anywhere.

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
  for (let i = 0; i < 4; i++) {
    await new Promise((resolve) => setImmediate(resolve));
  }
}

function widgetWithPresence({ reports = true, storage, href, declined, pollMs = 0 } = {}) {
  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: href || 'https://shop.example.test/pricing',
  });

  const calls = [];

  // Seeded, because the widget resolves its own anonymous id before bootstrap
  // answers -- the id in the report is the CLIENT's, not the one the server
  // echoes back, and that is the right way round.
  const seed = {
    'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
    'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
  };

  if (declined) {
    seed['wayfindr:site_public_shop:presence-declined'] = 'declined';
  }

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages: [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: storage === undefined ? memoryStorage(seed) : storage,
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: pollMs,
    fetch: async (url, init) => {
      calls.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: {
            site: {
              public_key: 'site_public_shop',
              settings: {},
              color: 'blue',
              presence: { reports, every: 45 },
            },
            visitor: { anonymous_id: 'anon-shop', token: 'visitor-token-shop' },
          },
        });
      }

      return jsonResponse(202, { data: { reports: true } });
    },
  });

  return { widget, calls, dom };
}

const presenceCalls = (calls) => calls.filter((c) => c.url.endsWith('/api/widget/presence'));

test('a site that reports gets a heartbeat, and the page address is stripped first', async () => {
  // Client-side sanitising is not belt and braces: a query string that reaches
  // Wayfindr has already reached proxies and access logs on the way.
  const { widget, calls } = widgetWithPresence({
    href: 'https://shop.example.test/account/reset?reset_token=abc123#tok',
  });

  await widget.open();
  await settle();

  const sent = presenceCalls(calls);

  assert.equal(sent.length, 1, 'no presence report was sent');
  assert.equal(sent[0].body.page_url, 'https://shop.example.test/account/reset');
  assert.equal(sent[0].body.anonymous_id, 'anon-shop');
  assert.ok(!('last_seen_at' in sent[0].body), 'the widget sent a timestamp the server cannot verify');
});

test('a site that has not opted in is never reported to', async () => {
  const { widget, calls } = widgetWithPresence({ reports: false });

  await widget.open();
  await settle();

  assert.equal(presenceCalls(calls).length, 0);
});

test('a visitor who declined earlier is not reported', async () => {
  const { widget, calls } = widgetWithPresence({ declined: true });

  await widget.open();
  await settle();

  assert.equal(presenceCalls(calls).length, 0, 'a remembered decline was ignored');
});

test('presence stays off when a decline could not be remembered', async () => {
  // The fail-closed rule. An embed passing `storage: null`, a private window,
  // or a browser rejecting writes all leave a "no" that evaporates on the next
  // navigation -- so we do not start, rather than offering a control that
  // appears to work and does not.
  const { widget, calls } = widgetWithPresence({ storage: null });

  await widget.open();
  await settle();

  assert.equal(presenceCalls(calls).length, 0, 'reported despite being unable to remember a decline');
});

test('a storage that accepts writes and forgets them is treated as no storage', async () => {
  // Worse than throwing, because `setItem` succeeds. Only reading back tells
  // you, which is why the probe writes a sentinel rather than trusting the call.
  const amnesiac = {
    getItem: () => null,
    setItem: () => {},
    removeItem: () => {},
  };

  const { widget, calls } = widgetWithPresence({ storage: amnesiac });

  await widget.open();
  await settle();

  assert.equal(presenceCalls(calls).length, 0, 'a storage that silently forgets was trusted');
});

test('declining stops the reporting and says so', async () => {
  const { widget, calls } = widgetWithPresence();

  await widget.open();
  await settle();

  assert.equal(presenceCalls(calls).length, 1);

  const decline = widget.root.querySelector('.wayfindr-widget__presence-decline');

  assert.ok(decline, 'no decline control was rendered');
  decline.click();
  await settle();

  const copy = widget.root.querySelector('.wayfindr-widget__presence-copy');

  assert.match(copy.textContent, /Not sharing/);
  assert.equal(decline.hidden, true, 'the control still offers to stop something already stopped');
});

test('the disclosure is on the page before the first report leaves', async () => {
  // Reporting before the visitor could have seen the notice is the same defect
  // as not having one, arriving a few hundred milliseconds earlier.
  const { widget, calls } = widgetWithPresence();

  await widget.open();
  await settle();

  const disclosure = widget.root.querySelector('.wayfindr-widget__presence');

  assert.ok(disclosure, 'no disclosure element exists');
  assert.equal(disclosure.hidden, false, 'the notice is hidden while presence reports');
  assert.equal(presenceCalls(calls).length, 1);
});

test('hiding and re-showing the tab does not restart a declined visitor', async () => {
  // The fail-closed returns used to leave `presenceConfig` set, and the
  // visibility handler gates on exactly that -- so a tab switch restarted
  // reporting underneath a notice saying pages were not being shared.
  const { widget, calls, dom } = widgetWithPresence({ declined: true });

  await widget.open();
  await settle();

  assert.equal(presenceCalls(calls).length, 0);

  Object.defineProperty(dom.window.document, 'visibilityState', { value: 'visible', configurable: true });
  dom.window.document.dispatchEvent(new dom.window.Event('visibilitychange'));
  await settle();

  assert.equal(presenceCalls(calls).length, 0, 'a tab switch restarted reporting for somebody who declined');
});

test('the page is read from the browser location, not only an injected one', async () => {
  // Production embeds do not pass `options.location`; init() resolves the
  // browser's. Reading the option meant every heartbeat omitted page_url and
  // the board could never say where anybody was.
  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/deep/page',
  });

  const calls = [];

  const widget = Wayfindr.init({
    document: dom.window.document,
    // location deliberately omitted, exactly as a real embed does.
    navigator: { languages: [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: memoryStorage({
      'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
      'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
    }),
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: 0,
    fetch: async (url, init) => {
      calls.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: {
            site: { public_key: 'site_public_shop', settings: {}, color: 'blue', presence: { reports: true, every: 45 } },
            visitor: { anonymous_id: 'anon-shop', token: 'visitor-token-shop' },
          },
        });
      }

      return jsonResponse(202, { data: { reports: true } });
    },
  });

  await widget.open();
  await settle();

  const sent = presenceCalls(calls);

  assert.equal(sent.length, 1);
  assert.equal(sent[0].body.page_url, 'https://shop.example.test/deep/page');
});

test('the client sanitises a URL a host hands it directly', async () => {
  // createClient() is a public integration surface. A host calling
  // reportPresence(window.location.href) must not put a token on the wire just
  // because it bypassed the widget's own sanitising.
  const calls = [];

  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    anonymousId: 'anon-direct',
    fetch: async (url, init) => {
      calls.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      return jsonResponse(202, { data: { reports: true } });
    },
  });

  await client.reportPresence('https://shop.example.test/reset?reset_token=direct#frag');

  assert.equal(calls.length, 1);
  assert.equal(calls[0].body.page_url, 'https://shop.example.test/reset');
});

test('destroying the widget stops it reporting', async (t) => {
  // Two ways a destroyed widget can keep reporting, and the test has to reach
  // BOTH: the visibilitychange listener waking it, and the interval it left
  // running. An earlier version only dispatched a visibility event, which the
  // existing removeEventListener already covered -- so deleting the timer
  // teardown changed nothing and the test passed against the bug.
  //
  // A real interval, deliberately short, is what reaches the second one.
  const { widget, calls, dom } = widgetWithPresence({ pollMs: 20 });

  await widget.open();
  await settle();

  const before = presenceCalls(calls).length;

  assert.ok(before > 0, 'nothing was reported, so this proves nothing');

  widget.destroy();

  Object.defineProperty(dom.window.document, 'visibilityState', { value: 'visible', configurable: true });
  dom.window.document.dispatchEvent(new dom.window.Event('visibilitychange'));

  // Long enough for several missed ticks of a 20ms interval.
  await new Promise((resolve) => setTimeout(resolve, 120));
  await settle();

  assert.equal(presenceCalls(calls).length, before, 'a destroyed widget reported again');
});

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

// Both phases, deliberately. Presence now crosses a timer boundary on its way
// out -- the config fetch resolves as a promise, the first report waits for a
// paint, and where there is no rAF that wait is a timeout. Draining only the
// check phase left the last hop pending often enough to look like a flake.
async function settle() {
  for (let i = 0; i < 6; i++) {
    await new Promise((resolve) => setImmediate(resolve));
    await new Promise((resolve) => setTimeout(resolve, 0));
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

      // Presence config comes from HERE, not bootstrap: the visitors this
      // feature exists to see never open the panel, and bootstrap both
      // requires that and records it as contact.
      if (url.includes('/api/widget/appearance')) {
        return jsonResponse(200, {
          data: {
            appearance: { position: 'right' },
            presence: { reports: reports, every: 45 },
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

      // Presence config comes from HERE, not bootstrap: the visitors this
      // feature exists to see never open the panel, and bootstrap both
      // requires that and records it as contact.
      if (url.includes('/api/widget/appearance')) {
        return jsonResponse(200, {
          data: {
            appearance: { position: 'right' },
            presence: { reports: true, every: 45 },
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

test('a visitor who never opens the widget is reported anyway', async (t) => {
  // The whole point of the feature, and it did not work. Presence used to be
  // configured from the bootstrap answer, and bootstrap only runs when the
  // panel is opened -- so the only visitors ever reported were the ones who
  // had made contact, which is the population this explicitly is not about.
  // Worse, opening the panel is itself contact, so by the time a visitor
  // qualified for a heartbeat the server had already stopped counting them
  // as presence-only.
  const { widget, calls } = widgetWithPresence();

  t.after(() => widget.destroy());

  // No widget.open(). Nobody clicked anything.
  await settle();

  const sent = presenceCalls(calls);

  assert.equal(sent.length, 1, 'a visitor who only loaded the page was never reported');
  assert.equal(sent[0].body.anonymous_id, 'anon-shop');
  assert.ok(
    !calls.some((c) => c.url.endsWith('/api/widget/bootstrap')),
    'presence made contact on the visitor\'s behalf just to configure itself',
  );
});

test('the notice is readable without opening the panel', async (t) => {
  // A disclosure inside the panel is an explanation offered only to the people
  // it does not apply to. It has to be on the page, outside the panel, before
  // the first heartbeat leaves.
  const { widget, calls } = widgetWithPresence();

  t.after(() => widget.destroy());

  await settle();

  const notice = widget.root.querySelector('.wayfindr-widget__presence');
  const panel = widget.root.querySelector('.wayfindr-widget__panel');

  assert.ok(notice, 'no disclosure element exists');
  assert.equal(notice.hidden, false, 'the notice is hidden while presence reports');
  assert.equal(panel.hidden, true, 'this test proves nothing if the panel is open');
  assert.equal(panel.contains(notice), false, 'the notice is inside the panel nobody opened');
  assert.equal(presenceCalls(calls).length, 1);
});

test('a decline in another tab stops this one', async (t) => {
  // Same site, several tabs. "Stop sharing" writes a site-wide key but can
  // only stop the instance that was clicked -- every other loaded tab went on
  // reporting the visitor who had just said no.
  const { widget, calls, dom } = widgetWithPresence({ pollMs: 20 });

  t.after(() => widget.destroy());

  await settle();

  assert.ok(presenceCalls(calls).length > 0, 'nothing was reported, so this proves nothing');

  // What the other tab's click leaves behind, and the event the browser fires
  // in THIS document as a result.
  const key = 'wayfindr:site_public_shop:presence-declined';
  const event = new dom.window.Event('storage');

  event.key = key;
  event.newValue = 'declined';
  dom.window.dispatchEvent(event);

  const afterDecline = presenceCalls(calls).length;

  await new Promise((resolve) => setTimeout(resolve, 80));
  await settle();

  assert.equal(
    presenceCalls(calls).length,
    afterDecline,
    'another tab kept reporting somebody who declined',
  );

  const notice = widget.root.querySelector('.wayfindr-widget__presence-copy');

  assert.match(notice.textContent, /Not sharing/i, 'the tab still says it is sharing');
});

test('a decline is honoured even if the storage event never arrives', async (t) => {
  // The event is the promptness; re-reading the key on every beat is the
  // guarantee. Storage events are not delivered in every embedding -- a
  // sandboxed iframe, an extension context -- and the decline still has to win.
  const values = new Map(Object.entries({
    'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
    'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
  }));

  const storage = {
    getItem: (key) => (values.has(key) ? values.get(key) : null),
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
  };

  const { widget, calls } = widgetWithPresence({ storage, pollMs: 20 });

  t.after(() => widget.destroy());

  await settle();

  assert.ok(presenceCalls(calls).length > 0, 'nothing was reported, so this proves nothing');

  // The other tab's write, with no event dispatched at all.
  values.set('wayfindr:site_public_shop:presence-declined', 'declined');

  const afterDecline = presenceCalls(calls).length;

  await new Promise((resolve) => setTimeout(resolve, 80));
  await settle();

  assert.equal(
    presenceCalls(calls).length,
    afterDecline,
    'the heartbeat trusted its own memory over the visitor\'s decision',
  );
});

test('a token in the path never leaves the browser', async (t) => {
  // The query string and fragment were already dropped, and the path was
  // copied verbatim -- which is where this product's own reset route puts a
  // token. Redacting it on the server is too late: it has already crossed
  // every proxy and landed in access logs on both sides.
  const { widget, calls } = widgetWithPresence({
    href: 'https://shop.example.test/reset-password/9f2c8a1b4e6d7c3f0a5b2e8d1c4f7a9b',
  });

  t.after(() => widget.destroy());

  await settle();

  const sent = presenceCalls(calls);

  assert.equal(sent.length, 1);
  assert.equal(sent[0].body.page_url, 'https://shop.example.test/reset-password/[redacted]');
});

test('an ordinary page name survives redaction', async (t) => {
  // The rule is crude on purpose, but it must not eat the answer to "which
  // page" on every normal site.
  const { widget, calls } = widgetWithPresence({
    href: 'https://shop.example.test/account/billing-preferences',
  });

  t.after(() => widget.destroy());

  await settle();

  assert.equal(
    presenceCalls(calls)[0].body.page_url,
    'https://shop.example.test/account/billing-preferences',
  );
});

test('the first report waits for a paint, not just for the notice to exist', async (t) => {
  // Being in the document is not being visible. The element is inserted with
  // its styles unresolved, so reporting in the same turn as the config arrived
  // sent the first heartbeat before the browser had any opportunity to put the
  // notice in front of anybody.
  //
  // Microtasks are how "the same turn" is expressed here: the config resolves
  // as a promise, so draining promises without letting a frame happen is
  // exactly the window the visitor would have been reported in.
  const { widget, calls } = widgetWithPresence();

  t.after(() => widget.destroy());

  for (let i = 0; i < 20; i++) {
    await Promise.resolve();
  }

  assert.equal(
    presenceCalls(calls).length,
    0,
    'the first heartbeat left in the same turn the config arrived, before anything could be painted',
  );

  await settle();

  assert.equal(presenceCalls(calls).length, 1, 'the report never arrived at all');
});

test('a short secret in the path never leaves the browser either', async (t) => {
  // The client and server rules have to be the same rule. A disagreement shows
  // up as page addresses that change shape depending on which path they took,
  // and the client's is the one that decides what crosses the wire at all.
  const { widget, calls } = widgetWithPresence({
    href: 'https://shop.example.test/invite/A1B2C3',
  });

  t.after(() => widget.destroy());

  await settle();

  assert.equal(
    presenceCalls(calls)[0].body.page_url,
    'https://shop.example.test/invite/[redacted]',
  );
});

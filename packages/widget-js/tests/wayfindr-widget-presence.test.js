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

test('a returning visitor with a cached appearance is still told and still reported', async (t) => {
  // Everyone is a returning visitor after their first page. The cached
  // appearance used to end configuration there, so the response carrying
  // presence was never fetched -- no disclosure, no heartbeat, for almost
  // every real visitor. The earlier tests missed it because the harness seeds
  // no cached appearance, which is the least realistic state a browser is in.
  const seeded = {
    'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
    'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
    'wayfindr:site_public_shop:appearance': JSON.stringify({ position: 'right' }),
  };

  const values = new Map(Object.entries(seeded));
  const storage = {
    getItem: (key) => (values.has(key) ? values.get(key) : null),
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
  };

  const { widget, calls } = widgetWithPresence({ storage });

  t.after(() => widget.destroy());

  await settle();

  const notice = widget.root.querySelector('.wayfindr-widget__presence');

  assert.equal(notice.hidden, false, 'a returning visitor was never shown the notice');
  assert.equal(presenceCalls(calls).length, 1, 'a returning visitor was never reported');
});

test('a tab coming back to the foreground waits for the notice too', async (t) => {
  // If the config arrives while the tab is in the background, the notice has
  // never been painted. Foregrounding used to send the heartbeat synchronously
  // inside the visibilitychange handler, which is ahead of the first paint the
  // visitor could have seen anything in.
  const { widget, calls, dom } = widgetWithPresence();

  t.after(() => widget.destroy());

  Object.defineProperty(dom.window.document, 'visibilityState', { value: 'hidden', configurable: true });

  await settle();

  assert.equal(presenceCalls(calls).length, 0, 'a hidden tab reported');

  Object.defineProperty(dom.window.document, 'visibilityState', { value: 'visible', configurable: true });

  const beforePaint = presenceCalls(calls).length;

  dom.window.document.dispatchEvent(new dom.window.Event('visibilitychange'));

  assert.equal(
    presenceCalls(calls).length,
    beforePaint,
    'foregrounding reported synchronously, before anything could be painted',
  );

  await settle();

  assert.ok(presenceCalls(calls).length > beforePaint, 'foregrounding never resumed reporting');
});

test('a widget handed a document reports that document', async (t) => {
  // A host calling init({document: iframe.contentDocument}) without a location
  // got the surrounding page's address, which on a same-origin iframe can be an
  // unrelated part of the site. The document it was handed is the document it
  // is in.
  const inner = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/embedded/help',
  });

  // The surrounding page, as the widget's captured global sees it. Without
  // this the test cannot reach the bug at all: under Node the ambient global
  // has no location, so `options.location || root.location` is null either way
  // and the document fallback answers correctly by accident.
  const hadLocation = 'location' in globalThis;
  const previousLocation = globalThis.location;

  Object.defineProperty(globalThis, 'location', {
    value: { href: 'https://shop.example.test/dashboard' },
    configurable: true,
    writable: true,
  });

  t.after(() => {
    if (hadLocation) {
      Object.defineProperty(globalThis, 'location', {
        value: previousLocation, configurable: true, writable: true,
      });
    } else {
      delete globalThis.location;
    }
  });

  const calls = [];
  const values = new Map(Object.entries({
    'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
    'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
  }));

  const widget = Wayfindr.init({
    document: inner.window.document,
    // No location, exactly as such a host embeds it.
    navigator: { languages: [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: {
      getItem: (k) => (values.has(k) ? values.get(k) : null),
      setItem: (k, v) => values.set(k, String(v)),
      removeItem: (k) => values.delete(k),
    },
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: 0,
    fetch: async (url, init) => {
      calls.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.includes('/api/widget/appearance')) {
        return jsonResponse(200, { data: { appearance: { position: 'right' }, presence: { reports: true, every: 45 } } });
      }

      return jsonResponse(202, { data: { reports: true } });
    },
  });

  t.after(() => widget.destroy());

  await settle();

  assert.equal(
    presenceCalls(calls)[0].body.page_url,
    'https://shop.example.test/embedded/help',
    'the widget reported the surrounding page instead of its own document',
  );

});

test('the notice speaks the site language before the first heartbeat', async (t) => {
  // A silent visitor never bootstraps, and the site's configured default used
  // to arrive only with bootstrap -- so on a German site with a visitor whose
  // browser expresses no preference, the privacy notice was in English. A
  // disclosure somebody cannot read is not a disclosure.
  const values = new Map(Object.entries({
    'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
    'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
  }));

  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/preise',
  });

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages: [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: {
      getItem: (k) => (values.has(k) ? values.get(k) : null),
      setItem: (k, v) => values.set(k, String(v)),
      removeItem: (k) => values.delete(k),
    },
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: 0,
    fetch: async (url) => {
      if (url.includes('/api/widget/appearance')) {
        return jsonResponse(200, {
          data: {
            appearance: { position: 'right' },
            presence: { reports: true, every: 45 },
            locale: 'de',
          },
        });
      }

      return jsonResponse(202, { data: { reports: true } });
    },
  });

  t.after(() => widget.destroy());

  await settle();

  const copy = widget.root.querySelector('.wayfindr-widget__presence-copy');

  assert.match(copy.textContent, /Website kann sehen/, 'the notice was not in the site language');
  assert.equal(widget.root.lang, 'de');
});

test('a site that reports without page addresses sends none', async (t) => {
  // The server drops it on arrival too, but an address the operator has said
  // not to keep should never travel -- which is the same argument that put
  // sanitising in the client at all.
  const values = new Map(Object.entries({
    'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
    'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
  }));

  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/invite/ABCDEF',
  });

  const calls = [];

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages: [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: {
      getItem: (k) => (values.has(k) ? values.get(k) : null),
      setItem: (k, v) => values.set(k, String(v)),
      removeItem: (k) => values.delete(k),
    },
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: 0,
    fetch: async (url, init) => {
      calls.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.includes('/api/widget/appearance')) {
        return jsonResponse(200, {
          data: {
            appearance: { position: 'right' },
            presence: { reports: true, every: 45, page_urls: false },
          },
        });
      }

      return jsonResponse(202, { data: { reports: true } });
    },
  });

  t.after(() => widget.destroy());

  await settle();

  const sent = presenceCalls(calls);

  assert.equal(sent.length, 1, 'the visitor was not reported at all');
  assert.ok(!('page_url' in sent[0].body), 'a page address was sent despite the site turning them off');
});

test('an all-letters code in the path is redacted client-side', async (t) => {
  const { widget, calls } = widgetWithPresence({
    href: 'https://shop.example.test/invite/ABCDEF',
  });

  t.after(() => widget.destroy());

  await settle();

  assert.equal(
    presenceCalls(calls)[0].body.page_url,
    'https://shop.example.test/invite/[redacted]',
  );
});

test('the notice says what is actually collected', async (t) => {
  // A site with page addresses off sends only "somebody is here". A disclosure
  // claiming otherwise is untrue and a worse explanation than none: it
  // describes a sharing the visitor cannot stop, because it is not happening.
  const values = new Map(Object.entries({
    'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
    'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
  }));

  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/pricing',
  });

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages: [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: {
      getItem: (k) => (values.has(k) ? values.get(k) : null),
      setItem: (k, v) => values.set(k, String(v)),
      removeItem: (k) => values.delete(k),
    },
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: 0,
    fetch: async (url) => {
      if (url.includes('/api/widget/appearance')) {
        return jsonResponse(200, {
          data: { appearance: { position: 'right' }, presence: { reports: true, every: 45, page_urls: false } },
        });
      }

      return jsonResponse(202, { data: { reports: true } });
    },
  });

  t.after(() => widget.destroy());

  await settle();

  const copy = widget.root.querySelector('.wayfindr-widget__presence-copy');

  assert.match(copy.textContent, /not told which page/i, 'the notice claimed page sharing that is not happening');
});

test('a running reporter picks up a revised setting', async (t) => {
  // fetchSiteConfig() runs once per page load, so a tab open all afternoon
  // would keep the settings it started with and go on sending page addresses
  // an operator switched off hours ago. They are dropped before storage, but
  // they have already crossed the wire.
  let pageUrls = true;

  const values = new Map(Object.entries({
    'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
    'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
  }));

  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/pricing',
  });

  const calls = [];

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages: [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: {
      getItem: (k) => (values.has(k) ? values.get(k) : null),
      setItem: (k, v) => values.set(k, String(v)),
      removeItem: (k) => values.delete(k),
    },
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: 0,
    fetch: async (url, init) => {
      calls.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.includes('/api/widget/appearance')) {
        return jsonResponse(200, {
          data: { appearance: { position: 'right' }, presence: { reports: true, every: 45, page_urls: true } },
        });
      }

      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: {
            site: {
              public_key: 'site_public_shop',
              settings: {},
              color: 'blue',
              presence: { reports: true, every: 45, page_urls: pageUrls },
            },
            visitor: { anonymous_id: 'anon-shop', token: 'visitor-token-shop' },
          },
        });
      }

      return jsonResponse(202, { data: { reports: true } });
    },
  });

  t.after(() => widget.destroy());

  await settle();

  assert.ok(presenceCalls(calls)[0].body.page_url, 'the first heartbeat sent no page at all');

  // The operator switches page addresses off; the tab stays open and the
  // visitor opens the panel, which is the only fresh answer it will get.
  pageUrls = false;

  await widget.open();
  await settle();

  const notice = widget.root.querySelector('.wayfindr-widget__presence-copy');

  assert.match(notice.textContent, /not told which page/i, 'the notice still described page sharing');

  const before = presenceCalls(calls).length;

  dom.window.document.dispatchEvent(new dom.window.Event('visibilitychange'));
  await settle();

  const after = presenceCalls(calls).slice(before);

  assert.ok(after.length > 0, 'no further heartbeat was sent, so this proves nothing');
  assert.ok(
    after.every((c) => !('page_url' in c.body)),
    'the tab kept sending page addresses after the operator switched them off',
  );
});

test('punctuation does not launder a credential client-side either', async (t) => {
  // The client rule decides what leaves the browser at all, so a divergence
  // from the server's means the credential has already crossed the wire by the
  // time anything redacts it.
  const cases = [
    ['/invite/ABC-123', 'https://shop.example.test/invite/[redacted]'],
    ['/reset/abc_def123', 'https://shop.example.test/reset/[redacted]'],
    ['/t/A1B2-C3D4', 'https://shop.example.test/t/[redacted]'],
  ];

  for (const [path, expected] of cases) {
    const { widget, calls } = widgetWithPresence({ href: 'https://shop.example.test' + path });

    t.after(() => widget.destroy());

    await settle();

    assert.equal(presenceCalls(calls)[0].body.page_url, expected, path + ' was not redacted');
  }
});

test('hyphenated page names survive client-side too', async (t) => {
  for (const path of ['/billing-preferences', '/blog/2024-my-post', '/help/how-do-i-cancel', '/en-GB/pricing']) {
    const { widget, calls } = widgetWithPresence({ href: 'https://shop.example.test' + path });

    t.after(() => widget.destroy());

    await settle();

    assert.equal(
      presenceCalls(calls)[0].body.page_url,
      'https://shop.example.test' + path,
      path + ' was redacted',
    );
  }
});

test('a passive tab learns that presence was switched off', async (t) => {
  // The visitor this feature is about never opens the panel, so bootstrap
  // never runs and the page-load config fetch happens once. The heartbeat
  // answer is the only way such a tab ever hears about a change.
  let reports = true;

  const values = new Map(Object.entries({
    'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
    'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
  }));

  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/pricing',
  });

  const calls = [];

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages: [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: {
      getItem: (k) => (values.has(k) ? values.get(k) : null),
      setItem: (k, v) => values.set(k, String(v)),
      removeItem: (k) => values.delete(k),
    },
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: 20,
    fetch: async (url, init) => {
      calls.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.includes('/api/widget/appearance')) {
        return jsonResponse(200, {
          data: { appearance: { position: 'right' }, presence: { reports: true, every: 45, page_urls: true } },
        });
      }

      return jsonResponse(202, { data: { reports, every: 45, page_urls: true } });
    },
  });

  t.after(() => widget.destroy());

  await settle();

  assert.ok(presenceCalls(calls).length > 0, 'nothing was reported, so this proves nothing');

  // The operator turns it off. Nobody opens the panel; nothing reloads.
  reports = false;

  await new Promise((resolve) => setTimeout(resolve, 60));
  await settle();

  const settled = presenceCalls(calls).length;

  await new Promise((resolve) => setTimeout(resolve, 80));
  await settle();

  assert.equal(
    presenceCalls(calls).length,
    settled,
    'a passive tab kept reporting after presence was switched off',
  );

  const notice = widget.root.querySelector('.wayfindr-widget__presence');

  assert.equal(notice.hidden, true, 'the notice still claims the site is watching');
});

test('a partial answer does not re-enable address sharing', async (t) => {
  // A key the answer does not carry means unchanged, not allowed. Assigning
  // the settings wholesale meant a response without `page_urls` silently put
  // addresses an operator had switched off back on the wire.
  const values = new Map(Object.entries({
    'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
    'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
  }));

  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/pricing',
  });

  const calls = [];

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages: [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: {
      getItem: (k) => (values.has(k) ? values.get(k) : null),
      setItem: (k, v) => values.set(k, String(v)),
      removeItem: (k) => values.delete(k),
    },
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: 20,
    fetch: async (url, init) => {
      calls.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.includes('/api/widget/appearance')) {
        return jsonResponse(200, {
          data: { appearance: { position: 'right' }, presence: { reports: true, every: 45, page_urls: false } },
        });
      }

      // An answer that says nothing about page addresses.
      return jsonResponse(202, { data: { reports: true } });
    },
  });

  t.after(() => widget.destroy());

  await settle();
  await new Promise((resolve) => setTimeout(resolve, 80));
  await settle();

  const sent = presenceCalls(calls);

  assert.ok(sent.length > 1, 'only one heartbeat was sent, so this proves nothing');
  assert.ok(
    sent.every((c) => !('page_url' in c.body)),
    'a partial answer put page addresses back on the wire',
  );
});

test('a notice hidden by the host page is not a disclosure', async (t) => {
  // `visibility: hidden` occupies space, so the element still has client
  // rects: laid out, measurable and invisible. A check that only asked
  // geometry called it shown, and the host page owns the stylesheet, so this
  // is a shape somebody else can put us in.
  const dom = new JSDOM(
    '<!doctype html><html><head><style>#support{visibility:hidden}</style></head>'
    + '<body><div id="support"></div></body></html>',
    { url: 'https://shop.example.test/pricing' },
  );

  const values = new Map(Object.entries({
    'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
    'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
  }));

  const calls = [];

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages: [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: {
      getItem: (k) => (values.has(k) ? values.get(k) : null),
      setItem: (k, v) => values.set(k, String(v)),
      removeItem: (k) => values.delete(k),
    },
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: 0,
    fetch: async (url, init) => {
      calls.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.includes('/api/widget/appearance')) {
        return jsonResponse(200, {
          data: { appearance: { position: 'right' }, presence: { reports: true, every: 45, page_urls: true } },
        });
      }

      return jsonResponse(202, { data: { reports: true, every: 45, page_urls: true } });
    },
  });

  t.after(() => widget.destroy());

  await settle();

  assert.equal(
    presenceCalls(calls).length,
    0,
    'reported while the notice was invisible to the visitor',
  );
});

test('declining confirms what actually stopped', async (t) => {
  // On a site that never shared page addresses, "not sharing which pages you
  // visit" was already true before the click, so the confirmation confirmed
  // nothing and read like the control had failed.
  const values = new Map(Object.entries({
    'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
    'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
  }));

  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/pricing',
  });

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages: [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: {
      getItem: (k) => (values.has(k) ? values.get(k) : null),
      setItem: (k, v) => values.set(k, String(v)),
      removeItem: (k) => values.delete(k),
    },
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: 0,
    fetch: async (url) => {
      if (url.includes('/api/widget/appearance')) {
        return jsonResponse(200, {
          data: { appearance: { position: 'right' }, presence: { reports: true, every: 45, page_urls: false } },
        });
      }

      return jsonResponse(202, { data: { reports: true, every: 45, page_urls: false } });
    },
  });

  t.after(() => widget.destroy());

  await settle();

  widget.root.querySelector('.wayfindr-widget__presence-decline').click();

  await settle();

  const copy = widget.root.querySelector('.wayfindr-widget__presence-copy').textContent;

  assert.match(copy, /know you are here/i, 'the confirmation described something that had not changed');
});

test('the notice names what is kept, not only what is visible', async (t) => {
  // The record outlives the visit by 30 days, specifically so a later visit is
  // recognised as a return. Describing only live visibility told the visitor
  // the truth about the half they are less likely to object to.
  const { widget } = widgetWithPresence();

  t.after(() => widget.destroy());

  await settle();

  const copy = widget.root.querySelector('.wayfindr-widget__presence-copy').textContent;

  assert.match(copy, /30 days/, 'the notice did not mention how long the visit is kept');
  assert.match(copy, /been here before/i, 'the notice did not mention being recognised on a return');
});

test('the retention promise is conditional, because the deletion is', async (t) => {
  // Only a visitor who never makes contact is pruned. Somebody who opens the
  // widget keeps their record indefinitely as part of support history, so
  // "remembers this visit for 30 days" promised a deletion that does not
  // happen to most of the people reading it.
  const { widget } = widgetWithPresence();

  t.after(() => widget.destroy());

  await settle();

  const copy = widget.root.querySelector('.wayfindr-widget__presence-copy').textContent;

  assert.match(copy, /been here before/i, 'the notice did not mention being recognised on a return');
  assert.match(copy, /never get in touch/i, 'the notice promised deletion without saying who it applies to');
  assert.match(copy, /30 days/, 'the notice did not say how long');
});

test('a stale settings answer cannot undo a newer one', async (t) => {
  // Heartbeats overlap when one runs longer than the interval, and bootstrap
  // answers on its own schedule. An older response carrying page_urls:true
  // would otherwise reinstate addresses a newer one had turned off, for the
  // life of the tab.
  const values = new Map(Object.entries({
    'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
    'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
  }));

  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/pricing',
  });

  const calls = [];
  let heartbeats = 0;
  const gate = [];

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages: [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: {
      getItem: (k) => (values.has(k) ? values.get(k) : null),
      setItem: (k, v) => values.set(k, String(v)),
      removeItem: (k) => values.delete(k),
    },
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: 20,
    fetch: async (url, init) => {
      calls.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.includes('/api/widget/appearance')) {
        return jsonResponse(200, {
          data: { appearance: { position: 'right' }, presence: { reports: true, every: 45, page_urls: true } },
        });
      }

      heartbeats++;

      // The FIRST heartbeat is held open and answers with the old setting; the
      // second answers immediately with the new one. The first therefore
      // resolves last, which is the ordering being defended against.
      if (heartbeats === 1) {
        return new Promise((resolve) => {
          gate.push(() => resolve(jsonResponse(202, { data: { reports: true, every: 45, page_urls: true } })));
        });
      }

      return jsonResponse(202, { data: { reports: true, every: 45, page_urls: false } });
    },
  });

  t.after(() => widget.destroy());

  await settle();
  await new Promise((resolve) => setTimeout(resolve, 60));
  await settle();

  // Beats sent before the newer answer arrived legitimately carry a page
  // address; what matters is what happens AFTER the stale one lands.
  const beforeRelease = presenceCalls(calls).filter((c) => 'page_url' in c.body).length;

  gate.forEach((release) => release());

  await settle();
  await new Promise((resolve) => setTimeout(resolve, 80));
  await settle();

  const afterRelease = presenceCalls(calls).filter((c) => 'page_url' in c.body).length;

  assert.ok(heartbeats > 3, 'not enough heartbeats after the release to observe the ordering');
  assert.equal(
    afterRelease,
    beforeRelease,
    'a stale answer reinstated page addresses a newer answer had turned off',
  );
});

test('a decline binds bootstrap, not only the heartbeat', async (t) => {
  // Declining stops the heartbeat. A visitor who then opened the panel had
  // their page address submitted by bootstrap anyway, so the board and their
  // profile showed where somebody was who had just asked not to be followed --
  // and it reached the server before anything could drop it.
  const { widget, calls } = widgetWithPresence({ declined: true });

  t.after(() => widget.destroy());

  await settle();

  assert.equal(presenceCalls(calls).length, 0, 'a declined visitor was reported');

  await widget.open();
  await settle();

  const bootstrap = calls.filter((c) => c.url.endsWith('/api/widget/bootstrap'));

  assert.ok(bootstrap.length > 0, 'the panel never bootstrapped, so this proves nothing');
  assert.ok(
    bootstrap.every((c) => !c.body.page_url),
    'bootstrap submitted the page of a visitor who had declined',
  );
});

test('an overtaken page-load config cannot reinstate settings', async (t) => {
  // The config GET starts at page load and can be slow. A visitor who opens
  // the panel meanwhile gets a bootstrap answer that is NEWER, and applying
  // the older GET afterwards would reinstate settings the operator changed.
  const values = new Map(Object.entries({
    'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
    'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
  }));

  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/pricing',
  });

  const calls = [];
  const gate = [];

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages: [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: {
      getItem: (k) => (values.has(k) ? values.get(k) : null),
      setItem: (k, v) => values.set(k, String(v)),
      removeItem: (k) => values.delete(k),
    },
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: 0,
    fetch: async (url, init) => {
      calls.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      // Held open: this is the OLDER answer, and it says reporting is on.
      if (url.includes('/api/widget/appearance')) {
        return new Promise((resolve) => {
          gate.push(() => resolve(jsonResponse(200, {
            data: { appearance: { position: 'right' }, presence: { reports: true, every: 45, page_urls: true } },
          })));
        });
      }

      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: {
            site: { public_key: 'site_public_shop', settings: {}, color: 'blue', presence: { reports: false } },
            visitor: { anonymous_id: 'anon-shop', token: 'visitor-token-shop' },
          },
        });
      }

      return jsonResponse(202, { data: { reports: true, every: 45, page_urls: true } });
    },
  });

  t.after(() => widget.destroy());

  // The visitor opens the panel while the config read is still in flight.
  await widget.open();
  await settle();

  // Now the stale answer lands, saying reporting is on.
  gate.forEach((release) => release());

  await settle();

  assert.equal(
    presenceCalls(calls).length,
    0,
    'an overtaken config response started reporting the operator had switched off',
  );
});

test('a decline binds the first message too', async (t) => {
  // Bootstrap and the heartbeat were gated; starting a conversation was not.
  // The page a visitor declined to share travelled with their first message
  // and landed on the conversation, where it outlives the decline entirely.
  const values = new Map(Object.entries({
    'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
    'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
    'wayfindr:site_public_shop:presence-declined': 'declined',
  }));

  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/pricing',
  });

  const calls = [];

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages: [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: {
      getItem: (k) => (values.has(k) ? values.get(k) : null),
      setItem: (k, v) => values.set(k, String(v)),
      removeItem: (k) => values.delete(k),
    },
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: 0,
    fetch: async (url, init) => {
      calls.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.includes('/api/widget/appearance')) {
        return jsonResponse(200, {
          data: { appearance: { position: 'right' }, presence: { reports: true, every: 45, page_urls: true } },
        });
      }

      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: {
            site: { public_key: 'site_public_shop', settings: {}, color: 'blue', presence: { reports: true, every: 45, page_urls: true } },
            visitor: { anonymous_id: 'anon-shop', token: 'visitor-token-shop' },
          },
        });
      }

      if (url.endsWith('/api/conversations')) {
        return jsonResponse(201, { data: { support_code: 'WF-DECLINE1', status: 'open' } });
      }

      return jsonResponse(201, { data: { support_code: 'WF-DECLINE1', status: 'open', messages: [] } });
    },
  });

  t.after(() => widget.destroy());

  await settle();
  await widget.open();
  await settle();

  const textarea = widget.root.querySelector('.wayfindr-widget__textarea');

  textarea.value = 'My order has not arrived';
  widget.root.querySelector('.wayfindr-widget__form')
    .dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));

  await settle();

  const started = calls.filter((c) => c.url.endsWith('/api/conversations'));

  assert.ok(started.length > 0, 'no conversation was started, so this proves nothing');
  assert.ok(
    started.every((c) => !c.body.page_url),
    'the first message carried the page a declined visitor asked not to share',
  );
});

test('opening the panel early does not turn presence off', async (t) => {
  // Bootstrap's answer was discarded for having nothing to update, and the
  // page-load config read that arrived afterwards was discarded for being
  // overtaken -- so opening the panel while the config was in flight left the
  // visitor reported by neither path.
  const values = new Map(Object.entries({
    'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
    'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
  }));

  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/pricing',
  });

  const calls = [];
  const gate = [];

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages: [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: {
      getItem: (k) => (values.has(k) ? values.get(k) : null),
      setItem: (k, v) => values.set(k, String(v)),
      removeItem: (k) => values.delete(k),
    },
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: 0,
    fetch: async (url, init) => {
      calls.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.includes('/api/widget/appearance')) {
        return new Promise((resolve) => {
          gate.push(() => resolve(jsonResponse(200, {
            data: { appearance: { position: 'right' }, presence: { reports: true, every: 45, page_urls: true } },
          })));
        });
      }

      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: {
            site: {
              public_key: 'site_public_shop',
              settings: {},
              color: 'blue',
              presence: { reports: true, every: 45, page_urls: true },
            },
            visitor: { anonymous_id: 'anon-shop', token: 'visitor-token-shop' },
          },
        });
      }

      return jsonResponse(202, { data: { reports: true, every: 45, page_urls: true } });
    },
  });

  t.after(() => widget.destroy());

  // The panel is opened while the page-load config read is still in flight.
  await widget.open();
  await settle();

  gate.forEach((release) => release());
  await settle();

  assert.ok(
    presenceCalls(calls).length > 0,
    'opening the panel early left the visitor reported by neither path',
  );
});

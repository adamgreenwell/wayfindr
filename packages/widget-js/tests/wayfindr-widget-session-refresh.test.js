const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

// A visitor token is minted by bootstrap, which asks for nothing that is not
// displayed or published. Refresh is the one path that asks for a token you
// already hold, so it is what a server-side token lifetime would rely on — and
// what every consumer of the token has to be able to see the result of.

function jsonResponse(status, payload) {
  return { ok: status >= 200 && status < 300, status, json: async () => payload };
}

function memoryStorage(seed) {
  const values = new Map(Object.entries(seed || {}));

  return {
    getItem: (key) => (values.has(key) ? values.get(key) : null),
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
    snapshot: () => Object.fromEntries(values),
  };
}

async function settle() {
  await new Promise((resolve) => setImmediate(resolve));
  await new Promise((resolve) => setImmediate(resolve));
  await new Promise((resolve) => setImmediate(resolve));
}

function widgetForRefresh(options) {
  options = options || {};

  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });

  const storage = memoryStorage({
    'wayfindr:site_public_docs:anonymous-id': 'anon-docs',
    'wayfindr:site_public_docs:visitor-token': 'token-first',
    'wayfindr:site_public_docs:support-code': 'WF-DOCS',
  });

  const requests = [];

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    storage,
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    realtime: options.realtime || false,
    sessionRefreshMs: options.sessionRefreshMs,
    fetch: async (url, init) => {
      requests.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: {
            site: { public_key: 'site_public_docs', settings: {}, color: 'blue' },
            visitor: {
              anonymous_id: 'anon-docs',
              token: 'token-first',
              token_expires_at: options.tokenExpiresAt || null,
            },
          },
        });
      }

      if (url.endsWith('/api/widget/session')) {
        return options.sessionResponse
          ? options.sessionResponse()
          : jsonResponse(200, { data: { visitor: { anonymous_id: 'anon-docs', token: 'token-second' } } });
      }

      return jsonResponse(200, { data: {} });
    },
  });

  return { widget, storage, requests, dom };
}

test('refreshing trades the current token and remembers the new one', async () => {
  const { widget, storage, requests } = widgetForRefresh();
  await settle();

  const refreshed = await widget.client.refreshSession();
  await settle();

  assert.equal(refreshed, true);

  const call = requests.find((request) => request.url.endsWith('/api/widget/session'));

  assert.ok(call, 'the widget did not call the refresh endpoint');
  assert.equal(call.body.visitor_token, 'token-first', 'it must present the token it currently holds');
  assert.equal(call.body.site_public_key, 'site_public_docs');
  assert.equal(call.body.anonymous_id, 'anon-docs');

  assert.equal(
    storage.snapshot()['wayfindr:site_public_docs:visitor-token'],
    'token-second',
    'the refreshed token must survive a page reload',
  );
});

test('a declined refresh leaves the working token alone', async () => {
  // The visitor did nothing wrong and their session is still good: replacing a
  // valid token with nothing, or surfacing an error, would both be worse than
  // carrying on and letting the caller decide.
  const { widget, storage } = widgetForRefresh({
    sessionResponse: () => jsonResponse(401, { message: 'Visitor token is invalid.' }),
  });
  await settle();

  const refreshed = await widget.client.refreshSession();

  assert.equal(refreshed, false);
  assert.equal(storage.snapshot()['wayfindr:site_public_docs:visitor-token'], 'token-first');
});

test('refreshing without a token asks the server nothing', async () => {
  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });
  const requests = [];

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    storage: memoryStorage({}),
    realtime: false,
    messagePollMs: 0,
    cobrowseStatusPollMs: 0,
    fetch: async (url) => {
      requests.push(url);

      return jsonResponse(200, { data: { site: { public_key: 'site_public_docs', settings: {} }, visitor: {} } });
    },
  });

  const refreshed = await widget.client.refreshSession();

  assert.equal(refreshed, false);
  assert.equal(
    requests.filter((url) => url.endsWith('/api/widget/session')).length,
    0,
    'there is nothing to refresh, so there is nothing to ask',
  );
});

test('the realtime auth payload reflects a refreshed token rather than the one it replaced', async () => {
  // Reverb re-authorises on every reconnect. The payload used to be an object
  // literal built once at subscribe time, so a reconnect after a refresh would
  // present the token that had already been rotated away — and the failure
  // would appear only later, as a socket that would not re-auth.
  let captured = null;

  const { widget } = widgetForRefresh({
    realtime: {
      subscribe: (config) => {
        captured = config;

        return { unsubscribe: () => {} };
      },
    },
  });
  await settle();

  widget.client.subscribeToConversation('WF-DOCS', () => {}, () => {}, () => {});

  assert.ok(captured, 'the realtime layer was never handed a subscription');

  // The object stays for custom adapters written against the old contract...
  assert.equal(typeof captured.authPayload, 'object');
  assert.equal(captured.authPayload.visitor_token, 'token-first');

  // ...and the provider is what the built-in adapter reads.
  assert.equal(typeof captured.authPayloadProvider, 'function');
  assert.equal(captured.authPayloadProvider().visitor_token, 'token-first');

  await widget.client.refreshSession();
  await settle();

  assert.equal(
    captured.authPayloadProvider().visitor_token,
    'token-second',
    'the already-created subscription must authorise with the current token',
  );

  // The frozen object is deliberately NOT updated -- an adapter holding it has
  // the contract it was written against, which is what keeps it working.
  assert.equal(captured.authPayload.visitor_token, 'token-first');
});

test('with no advertised expiry the widget rotates on a steady interval', async () => {
  // Tokens do not expire today. The interval exists so that widgets already
  // embedded on customers' sites are rotating BEFORE a lifetime is switched
  // on server-side -- the script is cached and versionless, so the client
  // capability can never ship in step with the server rule.
  const { widget } = widgetForRefresh();
  await settle();

  assert.equal(widget.client.nextSessionRefreshDelay(Date.now()), 600000);
});

test('an advertised expiry is refreshed at its halfway point', async () => {
  // Halfway rather than near the edge: one failed attempt still leaves a whole
  // half-life to retry in before anything breaks.
  const now = Date.now();
  const { widget } = widgetForRefresh({
    tokenExpiresAt: new Date(now + 400000).toISOString(),
  });
  await settle();

  const delay = widget.client.nextSessionRefreshDelay(now);

  assert.ok(Math.abs(delay - 200000) < 2000, `expected about half of 400s, got ${delay}`);
});

test('a lifetime longer than the interval still rotates on the interval', async () => {
  // Half of a long life would be a long wait. The steady interval is the
  // ceiling, so a generous server-side lifetime does not slow rotation down.
  const now = Date.now();
  const { widget } = widgetForRefresh({
    tokenExpiresAt: new Date(now + 86400000).toISOString(),
  });
  await settle();

  assert.equal(widget.client.nextSessionRefreshDelay(now), 600000);
});

test('a token about to expire is refreshed now rather than at half of nothing', async () => {
  const now = Date.now();
  const { widget } = widgetForRefresh({
    tokenExpiresAt: new Date(now + 5000).toISOString(),
  });
  await settle();

  assert.equal(widget.client.nextSessionRefreshDelay(now), 30000);
});

test('an expiry already past asks immediately and lets the server decide', async () => {
  const now = Date.now();
  const { widget } = widgetForRefresh({
    tokenExpiresAt: new Date(now - 60000).toISOString(),
  });
  await settle();

  assert.equal(widget.client.nextSessionRefreshDelay(now), 30000);
});

test('the scheduled refresh actually fires and rotates the stored token', async () => {
  const { widget, storage, requests } = widgetForRefresh({ sessionRefreshMs: 20 });
  await settle();

  await new Promise((resolve) => setTimeout(resolve, 80));
  await settle();

  assert.ok(
    requests.some((request) => request.url.endsWith('/api/widget/session')),
    'the timer never reached the refresh endpoint',
  );
  assert.equal(storage.snapshot()['wayfindr:site_public_docs:visitor-token'], 'token-second');

  widget.destroy();
});

test('setting the interval to zero turns rotation off entirely', async () => {
  // Including when the server advertises an expiry -- otherwise disabling the
  // interval would still schedule the moment a lifetime appeared.
  const { widget, requests } = widgetForRefresh({
    sessionRefreshMs: 0,
    tokenExpiresAt: new Date(Date.now() + 120000).toISOString(),
  });
  await settle();

  assert.equal(widget.client.nextSessionRefreshDelay(Date.now()), 0);

  await new Promise((resolve) => setTimeout(resolve, 60));

  assert.equal(requests.filter((request) => request.url.endsWith('/api/widget/session')).length, 0);
});

test('destroying the widget stops it rotating', async () => {
  // The property is that no NEW cycle starts. A request already in flight when
  // destroy() lands still completes -- un-sending it would need abort plumbing
  // the widget does not have -- so this samples after that has settled and
  // then waits several intervals to prove the count stopped growing.
  const { widget, requests } = widgetForRefresh({ sessionRefreshMs: 20 });
  await settle();

  widget.destroy();

  const sessionCalls = () => requests.filter((request) => request.url.endsWith('/api/widget/session')).length;

  await new Promise((resolve) => setTimeout(resolve, 60));
  const settled = sessionCalls();

  await new Promise((resolve) => setTimeout(resolve, 150));

  assert.equal(sessionCalls(), settled, 'a destroyed widget started a new refresh cycle');
});

test('a visitor who never opened the widget still rotates their token', async () => {
  // The case rotation-on-init exists for. A visitor can hold a token -- minted
  // by presence or a previous visit, restored from storage on load -- without
  // ever having started a conversation, so there is no stored support code and
  // nothing triggers the panel-open bootstrap. Those are exactly the quiet
  // sessions a server-side lifetime would expire under, and waiting for a
  // panel that never opens would leave them stranded.
  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });
  const requests = [];

  const storage = memoryStorage({
    'wayfindr:site_public_docs:anonymous-id': 'anon-docs',
    'wayfindr:site_public_docs:visitor-token': 'token-first',
    // deliberately no support-code
  });

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    storage,
    realtime: false,
    messagePollMs: 0,
    cobrowseStatusPollMs: 0,
    sessionRefreshMs: 20,
    fetch: async (url) => {
      requests.push(url);

      if (url.endsWith('/api/widget/session')) {
        return jsonResponse(200, { data: { visitor: { anonymous_id: 'anon-docs', token: 'token-second' } } });
      }

      return jsonResponse(200, { data: { site: { public_key: 'site_public_docs', settings: {} }, visitor: {} } });
    },
  });

  await settle();
  await new Promise((resolve) => setTimeout(resolve, 90));

  assert.ok(
    requests.some((url) => url.endsWith('/api/widget/session')),
    'a visitor holding a token never rotated it because they never opened the panel',
  );
  assert.equal(storage.snapshot()['wayfindr:site_public_docs:visitor-token'], 'token-second');

  widget.destroy();
});

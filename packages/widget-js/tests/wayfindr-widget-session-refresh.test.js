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

  const storage = memoryStorage(Object.assign({
    'wayfindr:site_public_docs:anonymous-id': 'anon-docs',
    'wayfindr:site_public_docs:visitor-token': 'token-first',
    'wayfindr:site_public_docs:support-code': 'WF-DOCS',
  }, options.storageSeed || {}));

  const requests = [];
  let bootstrapCalls = 0;

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
    visitorToken: options.visitorToken,
    visitorTokenExpiresIn: options.visitorTokenExpiresIn,
    fetch: async (url, init) => {
      requests.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.endsWith('/api/widget/bootstrap')) {
        bootstrapCalls += 1;

        // The FIRST bootstrap always succeeds -- it is how the widget comes up.
        // Later ones can be made to fail, which is the only way to exercise a
        // recovery that does not actually mint a replacement token.
        if (options.bootstrapFailsAfterFirst && bootstrapCalls > 1) {
          throw new Error('bootstrap unreachable');
        }

        // Hold the response open, so a test can decide when -- or whether --
        // a bootstrap gets to finish.
        if (options.bootstrapGate) {
          await options.bootstrapGate;
        }

        return jsonResponse(200, {
          data: {
            site: { public_key: 'site_public_docs', settings: {}, color: 'blue' },
            visitor: {
              anonymous_id: 'anon-docs',
              token: 'token-first',
              token_expires_in: options.tokenExpiresIn === undefined ? null : options.tokenExpiresIn,
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

  const outcome = await widget.client.refreshSession();
  await settle();

  assert.equal(outcome, 'refreshed');

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

test('a refused token is reported as rejected, not merely failed', async () => {
  // Which failure it was decides the caller's next move: a refused token is
  // dead and asking again with it will never work, so the scheduler has to be
  // able to tell that apart from a server it could not reach.
  const { widget, storage } = widgetForRefresh({
    sessionResponse: () => jsonResponse(401, { message: 'Visitor token is invalid.' }),
  });
  await settle();

  assert.equal(await widget.client.refreshSession(), 'rejected');
  assert.equal(storage.snapshot()['wayfindr:site_public_docs:visitor-token'], 'token-first');
});

test('an unreachable server is reported as unavailable, so a good token is kept', async () => {
  // The opposite case, and the reason the two are not one outcome: re-minting
  // here would discard a perfectly valid session to fix a network blip.
  const { widget, storage } = widgetForRefresh({
    sessionResponse: () => {
      throw new Error('network down');
    },
  });
  await settle();

  assert.equal(await widget.client.refreshSession(), 'unavailable');
  assert.equal(storage.snapshot()['wayfindr:site_public_docs:visitor-token'], 'token-first');
});

test('refreshing without a token asks the server nothing', async () => {
  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });
  const requests = [];
  let bootstrapCalls = 0;

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

  assert.equal(await widget.client.refreshSession(), 'idle');
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

  assert.equal(await widget.client.refreshSession(), 'refreshed');
  await settle();

  assert.equal(
    captured.authPayloadProvider().visitor_token,
    'token-second',
    'the already-created subscription must authorise with the current token',
  );

  // And the legacy object is kept CURRENT, not merely intact. I originally
  // asserted the opposite here -- that freezing it was what kept an old
  // adapter working. It is not: that adapter was promised the credentials to
  // authorise with, so a frozen copy hands it a token that is about to be
  // refused, and the reconnect that reads it is precisely the one that fails.
  assert.equal(
    captured.authPayload.visitor_token,
    'token-second',
    'a custom adapter would reconnect with the token that was just rotated away',
  );
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
    tokenExpiresIn: 400,
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
    tokenExpiresIn: 86400,
  });
  await settle();

  assert.equal(widget.client.nextSessionRefreshDelay(now), 600000);
});

test('a token about to expire is refreshed inside its life, not after it', async () => {
  // The thirty-second floor used to win this comparison, so a five-second
  // lifetime waited thirty: the first refresh was scheduled twenty-five
  // seconds after the token had already died, and every request in between
  // presented a credential the server would refuse. The expiry is a ceiling.
  const now = Date.now();
  const { widget } = widgetForRefresh({
    tokenExpiresIn: 5,
  });
  await settle();

  const delay = widget.client.nextSessionRefreshDelay(now);

  assert.ok(delay > 0 && delay < 5000, `expected a delay inside the 5s lifetime, got ${delay}`);
  assert.ok(Math.abs(delay - 2500) < 500, `expected about half of 5s, got ${delay}`);
});

test('an expiry already past is refreshed at once rather than after a floor', async () => {
  // Nothing is left to halve. Asserting the delay NUMBER here would be
  // measuring the wrong thing -- the refresh fires during this test, adopts a
  // token the mock advertises no expiry for, and the delay afterwards reads as
  // a plain interval. The observable consequence is what matters: it asks now.
  const { requests } = widgetForRefresh({
    tokenExpiresIn: 0,
  });
  await settle();

  await new Promise((resolve) => setTimeout(resolve, 60));
  await settle();

  const calls = requests.filter((request) => request.url.endsWith('/api/widget/session'));

  assert.ok(calls.length > 0, 'an already-expired token was left to sit rather than refreshed');
});

test('an unreachable server is retried on the floor, not in a hot loop', async () => {
  // The other half of letting an expired token mean "now": once the expiry has
  // passed, the deadline-derived delay is always "now", so a server that
  // cannot be reached would be asked again immediately, and again, precisely
  // when it can least afford it. A dead token is no more valid thirty seconds
  // from now, so pacing the retry costs the visitor nothing.
  const { requests } = widgetForRefresh({
    tokenExpiresIn: 0,
    sessionResponse: () => Promise.reject(new Error('network down')),
  });
  await settle();

  await new Promise((resolve) => setTimeout(resolve, 250));
  await settle();

  const calls = requests.filter((request) => request.url.endsWith('/api/widget/session'));

  assert.ok(calls.length >= 1, 'no refresh was attempted at all');
  assert.ok(calls.length <= 2, `expected the retry to be paced, got ${calls.length} attempts in 250ms`);
});

test('a widget destroyed mid-refresh does not re-mint a token afterwards', async () => {
  // destroy() can land while a refresh is in flight. The reschedule at the end
  // of the cycle checked the stop flag; the bootstrap recovery did not, so a
  // rejected token could be re-minted and written to storage after teardown --
  // the one thing destroy() exists to prevent.
  let release;
  const held = new Promise((resolve) => {
    release = resolve;
  });

  const { widget, requests } = widgetForRefresh({
    sessionRefreshMs: 20,
    sessionResponse: () => held.then(() => jsonResponse(401, { message: 'gone' })),
  });
  await settle();

  await new Promise((resolve) => setTimeout(resolve, 60));
  await settle();

  const bootstrapsBefore = requests.filter((request) => request.url.endsWith('/api/widget/bootstrap')).length;

  widget.destroy();
  release();

  await settle();
  await new Promise((resolve) => setTimeout(resolve, 60));
  await settle();

  const bootstrapsAfter = requests.filter((request) => request.url.endsWith('/api/widget/bootstrap')).length;

  assert.equal(
    bootstrapsAfter,
    bootstrapsBefore,
    'a destroyed widget recovered from a rejected token and re-minted one',
  );
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
    tokenExpiresIn: 120,
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
  let bootstrapCalls = 0;

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

test('a rejected token is recovered by re-minting, not retried forever', async () => {
  // Reachable in ordinary use: a laptop sleeps, a background tab is suspended,
  // the timer fires late and the lifetime has already passed. Rescheduling
  // with the same dead token would leave an open conversation permanently
  // unauthorised with nothing anywhere saying why.
  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });
  const requests = [];
  let bootstrapCalls = 0;
  let bootstraps = 0;

  const storage = memoryStorage({
    'wayfindr:site_public_docs:anonymous-id': 'anon-docs',
    'wayfindr:site_public_docs:visitor-token': 'token-dead',
  });

  Wayfindr.init({
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
        return jsonResponse(401, { message: 'Visitor token is invalid.' });
      }

      if (url.endsWith('/api/widget/bootstrap')) {
        bootstraps += 1;

        return jsonResponse(200, {
          data: {
            site: { public_key: 'site_public_docs', settings: {} },
            visitor: { anonymous_id: 'anon-docs', token: 'token-reminted' },
          },
        });
      }

      return jsonResponse(200, { data: {} });
    },
  });

  await settle();
  await new Promise((resolve) => setTimeout(resolve, 90));

  assert.ok(bootstraps > 0, 'a dead token was never recovered from');
  assert.equal(
    storage.snapshot()['wayfindr:site_public_docs:visitor-token'],
    'token-reminted',
    'recovery must leave a usable token behind',
  );
});

test('an unreachable server does not throw the session away', async () => {
  // The other half of the same decision. Re-minting on a network blip would
  // discard a working session to fix nothing.
  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });
  let bootstraps = 0;

  const storage = memoryStorage({
    'wayfindr:site_public_docs:anonymous-id': 'anon-docs',
    'wayfindr:site_public_docs:visitor-token': 'token-good',
  });

  Wayfindr.init({
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
      if (url.endsWith('/api/widget/session')) {
        throw new Error('network down');
      }

      if (url.endsWith('/api/widget/bootstrap')) {
        bootstraps += 1;
      }

      return jsonResponse(200, {
        data: { site: { public_key: 'site_public_docs', settings: {} }, visitor: {} },
      });
    },
  });

  await settle();
  const bootstrapsAfterInit = bootstraps;

  await new Promise((resolve) => setTimeout(resolve, 90));

  assert.equal(bootstraps, bootstrapsAfterInit, 'a network blip re-minted a token that was probably fine');
  assert.equal(storage.snapshot()['wayfindr:site_public_docs:visitor-token'], 'token-good');
});

test('a token carrying a sooner deadline re-aims the pending timer', async () => {
  // A tab sitting on a ten-minute timer when an operator enables a five-minute
  // lifetime: the pending timer was scheduled against the old token and would
  // fire after the new one had already expired.
  const now = Date.now();
  const { widget } = widgetForRefresh();
  await settle();

  assert.equal(widget.client.nextSessionRefreshDelay(now), 600000, 'no expiry advertised yet');

  // Adopt a token whose life is much shorter than the standing interval.
  await widget.client.bootstrap('https://docs.example.test/', null);
  await settle();

  const shortened = widget.client.nextSessionRefreshDelay(now);

  assert.ok(shortened <= 600000, `expected the deadline to be honoured, got ${shortened}`);
});

test('an adopted expiry survives into the next page instance', async () => {
  // A round trip, not a seeded value: the deadline has to be WRITTEN when a
  // token is adopted, or the next page load restores a credential whose life
  // it cannot see and waits a full interval on a token already expired.
  const { widget, storage } = widgetForRefresh({ tokenExpiresIn: 400 });
  await settle();

  widget.destroy();

  // Same storage, brand-new widget -- exactly what a navigation does.
  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });

  const reopened = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    storage,
    realtime: false,
    messagePollMs: 0,
    cobrowseStatusPollMs: 0,
    fetch: async () => jsonResponse(200, { data: {} }),
  });

  const delay = reopened.client.nextSessionRefreshDelay(Date.now());

  assert.ok(
    Math.abs(delay - 200000) < 10000,
    `the adopted deadline did not survive the page instance, got ${delay}`,
  );

  reopened.destroy();
});

test('a seeded expiry is honoured on restore', async () => {
  // Storing the credential without its deadline makes a token near the end of
  // its life look non-expiring, so the first refresh is attempted some time
  // after enforcement has already killed it.
  const expiry = Date.now() + 400000;

  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    storage: memoryStorage({
      'wayfindr:site_public_docs:anonymous-id': 'anon-docs',
      'wayfindr:site_public_docs:visitor-token': 'token-restored',
      'wayfindr:site_public_docs:visitor-token-expires-at': String(expiry),
    }),
    realtime: false,
    messagePollMs: 0,
    cobrowseStatusPollMs: 0,
    fetch: async () => jsonResponse(200, { data: {} }),
  });

  const delay = widget.client.nextSessionRefreshDelay(Date.now());

  assert.ok(
    Math.abs(delay - 200000) < 5000,
    `a restored token should honour its stored deadline, got ${delay}`,
  );

  widget.destroy();
});

test('bootstrap does not start a second, independent refresh cycle', async () => {
  // If the timer handle is lost -- a `var` initialiser running after the
  // scheduling call would null it -- the guard reads false and every later
  // schedule adds ANOTHER live timer. Nothing errors; the widget just talks to
  // the server at a multiple of the interval it was configured with, and
  // destroy() can cancel none of them.
  const { widget, requests } = widgetForRefresh({ sessionRefreshMs: 20 });
  await settle();

  // Bootstrap again, the way opening the panel does.
  await widget.client.bootstrap('https://docs.example.test/', null);
  await settle();

  const before = requests.filter((r) => r.url.endsWith('/api/widget/session')).length;

  await new Promise((resolve) => setTimeout(resolve, 120));

  const during = requests.filter((r) => r.url.endsWith('/api/widget/session')).length - before;

  // ~6 for one cycle over 120ms at 20ms spacing; a second cycle doubles it.
  assert.ok(during > 0, 'rotation stopped entirely');
  assert.ok(during <= 9, `expected one refresh cycle, saw ${during} requests -- looks like two`);

  widget.destroy();
});

test('a failed refresh on a live token retries inside its life, not after the floor', async () => {
  // The retry floor and the expiry ceiling met here, and the floor won: a
  // token with 400ms left had its first attempt at 200ms, failed, and was then
  // pushed out to the 30-second floor -- which is the original defect back
  // again, reached by the fix for the hot loop rather than by the old code.
  //
  // Halving what remains is already a backoff, so no floor is wanted while the
  // token is alive: 200ms, 100ms, 50ms, converging on the deadline.
  const { requests } = widgetForRefresh({
    tokenExpiresIn: 0.4,
    sessionResponse: () => Promise.reject(new Error('network down')),
  });
  await settle();

  await new Promise((resolve) => setTimeout(resolve, 380));
  await settle();

  const calls = requests.filter((request) => request.url.endsWith('/api/widget/session'));

  assert.ok(
    calls.length >= 2,
    `expected repeated retries inside the 400ms lifetime, got ${calls.length}`,
  );
});

test('a recovery bootstrap that mints nothing is paced, not retried in a loop', async () => {
  // A dead token is rejected, so recovery runs -- and recovery can fail too,
  // on a 429, a 5xx or no network at all. The token is then still dead, so the
  // next delay is "now" and the widget reissues BOTH a session request and a
  // bootstrap on every pass. Swallowing the bootstrap failure and treating
  // 'rejected' as recovered is what made that reachable.
  const { requests } = widgetForRefresh({
    tokenExpiresIn: 0,
    sessionResponse: () => jsonResponse(401, { message: 'gone' }),
    bootstrapFailsAfterFirst: true,
  });
  await settle();

  await new Promise((resolve) => setTimeout(resolve, 250));
  await settle();

  const sessionCalls = requests.filter((request) => request.url.endsWith('/api/widget/session'));
  const bootstrapCalls = requests.filter((request) => request.url.endsWith('/api/widget/bootstrap'));

  assert.ok(sessionCalls.length >= 1, 'the dead token was never retried at all');
  assert.ok(
    sessionCalls.length <= 2,
    `expected the retry to be paced, got ${sessionCalls.length} session attempts in 250ms`,
  );
  assert.ok(
    bootstrapCalls.length <= 3,
    `expected recovery to be paced, got ${bootstrapCalls.length} bootstraps in 250ms`,
  );
});

test('a supplied token with no stated lifetime is refreshed early, not in ten minutes', async () => {
  // An unknown lifetime is not an absent one. A host that hands over a token
  // without saying how long it lasts could be handing over a five-minute one,
  // and waiting the ten-minute default means the first refresh lands after it
  // is dead. `createClient` is a public integration surface, so this is
  // reachable without the widget's own bootstrap arriving moments later to
  // supply the real deadline -- which is why the bootstrap is held open here.
  const now = Date.now();
  const { widget } = widgetForRefresh({
    visitorToken: 'host-supplied-token',
    bootstrapGate: new Promise(() => {}),
  });
  await settle();

  assert.equal(widget.client.nextSessionRefreshDelay(now), 30000);
});

test('a supplied token whose lifetime IS stated is scheduled against it', async () => {
  // And when the host does say, that is the deadline -- halved, like any other.
  const now = Date.now();
  const { widget } = widgetForRefresh({
    visitorToken: 'host-supplied-token',
    visitorTokenExpiresIn: 400,
    bootstrapGate: new Promise(() => {}),
  });
  await settle();

  const delay = widget.client.nextSessionRefreshDelay(now);

  assert.ok(Math.abs(delay - 200000) < 2000, `expected about half of 400s, got ${delay}`);
});

test('a superseded bootstrap does not count as having adopted a token', async () => {
  // The foundation the recovery check rests on. When two bootstraps overlap,
  // the loser takes the stale-ticket branch and returns its response -- token
  // and all -- WITHOUT adopting it. Reading that response would call recovery
  // successful; counting adoptions does not, which is the whole point.
  let release;
  const gate = new Promise((resolve) => {
    release = resolve;
  });

  const { widget } = widgetForRefresh({
    bootstrapGate: gate,
    sessionRefreshMs: 0,
  });
  release();
  await settle();

  const before = widget.client.visitorTokenGeneration();

  // Two bootstraps in flight at once; the second bumps the ticket.
  const loser = widget.client.bootstrap('https://docs.example.test/');
  const winner = widget.client.bootstrap('https://docs.example.test/');

  await loser;
  await winner;
  await settle();

  assert.equal(
    widget.client.visitorTokenGeneration(),
    before + 1,
    'the superseded bootstrap adopted a token as well as the superseding one',
  );
});


const EXPIRY_KEY = 'wayfindr:site_public_docs:visitor-token-expires-at';

test('a token restored with no expiry record beside it is treated as unknown', async () => {
  // The upgrade path. Storage written by a widget version that kept no expiry
  // has the token and nothing else -- which is exactly what "the server said
  // there is no expiry" used to look like, because that case DELETED the key.
  // One absent key cannot mean both, so the absent case now writes a marker
  // and a bare token means nobody recorded a lifetime.
  //
  // Bootstrap is held open: in the widget it would adopt a server-advertised
  // lifetime moments later, but createClient consumers have no such rescue.
  const now = Date.now();
  const { widget } = widgetForRefresh({
    bootstrapGate: new Promise(() => {}),
  });
  await settle();

  assert.equal(widget.client.nextSessionRefreshDelay(now), 30000);
});

test('a token restored with a recorded absence waits the full interval', async () => {
  // The other side of the same key. This widget wrote the marker, so the
  // absence is a fact rather than a gap, and there is nothing to probe for.
  const now = Date.now();
  const { widget } = widgetForRefresh({
    storageSeed: { [EXPIRY_KEY]: 'none' },
    bootstrapGate: new Promise(() => {}),
  });
  await settle();

  assert.equal(widget.client.nextSessionRefreshDelay(now), 600000);
});

test('adopting a token the server gave no lifetime for records that absence', async () => {
  // Closing the loop: the marker has to actually be written, or the next page
  // load reads this session's token as a legacy one and probes forever.
  const { storage } = widgetForRefresh({
    tokenExpiresIn: null,
  });
  await settle();

  assert.equal(storage.getItem(EXPIRY_KEY), 'none');
});

test('the unknown-lifetime probe never outlasts a configured interval', async () => {
  // The probe is meant to be SOONER than the default, never later than what
  // the integrator asked for -- a caller rotating every two seconds did not
  // request a thirty-second wait because storage happened to be old.
  const now = Date.now();
  const { widget } = widgetForRefresh({
    sessionRefreshMs: 2000,
    bootstrapGate: new Promise(() => {}),
  });
  await settle();

  assert.equal(widget.client.nextSessionRefreshDelay(now), 2000);
});

test('an advertised lifetime counts from when the request left, not when it answered', async () => {
  // The server measures a lifetime from the moment it mints the token. Time
  // spent in the network -- or in a suspended tab holding a buffered response,
  // or a laptop that slept -- is life already gone. Dating the deadline from
  // the moment JavaScript handles the answer credits the token with time it
  // does not have, and once the server enforces the lifetime that is a window
  // where every request the widget makes is refused.
  //
  // A one-second lifetime whose answer takes 400ms: by 450ms in, a bit over
  // half is spent, so roughly 275ms remains to halve. Anchoring to the
  // response instead would still believe it had nearly a full second.
  const { widget } = widgetForRefresh({
    tokenExpiresIn: 4,
    bootstrapGate: new Promise((resolve) => setTimeout(resolve, 2000)),
  });

  await new Promise((resolve) => setTimeout(resolve, 2050));
  await settle();

  const delay = widget.client.nextSessionRefreshDelay(Date.now());

  // Half of the ~1950ms actually left is about 975. Anchoring to the response
  // would believe nearly the whole four seconds remained and answer ~1985, so
  // the bound sits well clear of both rather than between two close values.
  assert.ok(
    delay < 1400,
    `expected the 2s spent in flight to count against the 4s lifetime, got ${delay}`,
  );
});

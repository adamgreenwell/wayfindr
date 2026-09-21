const assert = require('node:assert/strict');
const test = require('node:test');

const Wayfindr = require('../src/wayfindr-widget.js');

// Joining a sibling tab's token records what we know about its LIFETIME, and
// "unknown" is not "none".
//
// The widget stores the deadline beside the credential so a later page load can
// schedule a refresh before the token dies. That record has three readings:
// a number is a deadline, `none` means the token never expires, and no record
// at all means the lifetime is unknown and worth one early probe.
//
// A tab that joins a sibling's token was never told that token's lifetime. The
// joining tab gets it right in memory -- it probes early -- but the value it
// leaves behind is what the NEXT page load acts on.

const SITE = 'site_public_expiry';
const TOKEN_KEY = 'wayfindr:' + SITE + ':visitor-token';
const EXPIRY_KEY = 'wayfindr:' + SITE + ':visitor-token-expires-at';

function sharedExpiryStorage(seed) {
  const values = new Map(Object.entries(seed || {}));

  return {
    getItem: (key) => (values.has(key) ? values.get(key) : null),
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
  };
}

function sharedExpiryClient({ storage, anonymousId = 'anon-expiry', mintedToken, expiresIn = null, visitorToken, siblingWhileInFlight }) {
  let siblingRan = false;

  return Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: SITE,
    anonymousId,
    visitorToken,
    storage,
    fetch: async (url) => {
      if (url.endsWith('/api/widget/bootstrap')) {
        // A sibling tab comes up WHILE our request is in flight. That is the
        // only way a join happens: a tab that finds the token already in
        // storage at construction simply adopts it, and never reaches the
        // joining branch. A REAL client, not a hand-written token, because
        // what it records is part of what is being tested.
        if (siblingWhileInFlight && !siblingRan) {
          siblingRan = true;

          await sharedExpiryClient({
            storage,
            anonymousId,
            mintedToken: siblingWhileInFlight.token,
            expiresIn: siblingWhileInFlight.expiresIn,
          }).bootstrap(null, null);
        }

        return {
          ok: true,
          status: 200,
          json: async () => ({
            data: {
              site: { public_key: SITE, settings: {} },
              visitor: { anonymous_id: anonymousId, token: mintedToken, token_expires_in: expiresIn },
            },
          }),
        };
      }

      return { ok: true, status: 200, json: async () => ({ data: {} }) };
    },
  });
}

test('joining a sibling token does not record its lifetime as "none"', async () => {
  const storage = sharedExpiryStorage();

  // Our tab bootstraps from nothing. The sibling comes up mid-request and
  // publishes a token that DOES expire, with its deadline beside it.
  await sharedExpiryClient({
    storage,
    mintedToken: 'token-ours',
    expiresIn: 600,
    siblingWhileInFlight: { token: 'token-sibling', expiresIn: 600 },
  }).bootstrap(null, null);

  assert.equal(storage.getItem(TOKEN_KEY), 'token-sibling', 'we should have joined the sibling rather than published over it');

  const afterJoin = storage.getItem(EXPIRY_KEY);

  // The defect: the join knows the lifetime was stated to somebody else -- its
  // own comment says it "probes early rather than assuming it never expires" --
  // and then writes down the one value that means it never expires.
  assert.ok(
    afterJoin === null || !afterJoin.startsWith('none'),
    'joining must not record "never expires" about a token that does: got ' + afterJoin
  );
});

test('a page load after a join probes early instead of waiting the full interval', async () => {
  const storage = sharedExpiryStorage();

  await sharedExpiryClient({
    storage,
    mintedToken: 'token-ours',
    expiresIn: 600,
    siblingWhileInFlight: { token: 'token-sibling', expiresIn: 600 },
  }).bootstrap(null, null);

  // A later load restores the credential and whatever was left beside it. This
  // is the tab that pays for the downgrade: the joining tab compensated in
  // memory, and memory did not survive.
  // Constructed with NO `visitorToken`, so it restores the credential and the
  // record beside it from storage — which is what a page load does. Handing it
  // the token instead takes the host-supplied branch, which probes early for
  // its own reasons and would hide exactly what this is measuring.
  const restored = sharedExpiryClient({
    storage,
    anonymousId: 'anon-expiry',
    mintedToken: 'token-later',
  });

  const delay = restored.nextSessionRefreshDelay(Date.now());

  // 600000 is the default interval, i.e. "this never expires, ask again in ten
  // minutes". A token whose lifetime we were never told must be probed at the
  // 30s floor instead.
  assert.notEqual(delay, 600000, 'a joined token must not be scheduled as if it never expires');
  assert.ok(delay <= 30000, 'expected an early probe, got ' + delay);
});

const assert = require('node:assert/strict');
const test = require('node:test');

const Wayfindr = require('../src/wayfindr-widget.js');

// A conversation belongs to the SESSION that opened it, and the session rides
// inside the visitor token. The widget keeps that token and the active support
// code in two separate, site-wide storage keys, so they can drift apart — and
// once ownership is enforced, a pair naming two different sessions costs the
// visitor their conversation: the restore gets a 404 and the code is forgotten.
//
// Both tests below are about keeping that pair naming ONE session.

const TOKEN_KEY = 'wayfindr:site_public_own:visitor-token';

function jsonResponse(status, payload) {
  return { ok: status >= 200 && status < 300, status, json: async () => payload };
}

function memoryStorage(seed) {
  const values = new Map(Object.entries(seed || {}));

  return {
    getItem: (key) => (values.has(key) ? values.get(key) : null),
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
  };
}

function clientForOwnership(options) {
  options = options || {};

  const storage = options.storage || memoryStorage();
  const requests = [];
  let bootstrapped = false;

  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_own',
    anonymousId: 'anon-own',
    storage,
    fetch: async (url, init) => {
      requests.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.endsWith('/api/widget/bootstrap')) {
        bootstrapped = true;

        // A sibling tab can be made to store its own token while ours is in
        // flight, which is the race that leaves the pair naming two sessions.
        if (options.siblingStoresWhileInFlight) {
          storage.setItem(TOKEN_KEY, options.siblingStoresWhileInFlight);
        }

        return jsonResponse(200, {
          data: {
            site: { public_key: 'site_public_own', settings: {} },
            visitor: {
              anonymous_id: 'anon-own',
              token: options.mintedToken || 'token-minted',
              token_expires_in: null,
            },
          },
        });
      }

      if (url.endsWith('/api/conversations')) {
        // A token minted before sessions were identified is refused here, and
        // 401 is the status whose recovery is a bootstrap.
        if (options.refuseUntilBootstrapped && !bootstrapped) {
          const error = new Error('Visitor session has expired.');
          error.status = 401;
          throw error;
        }

        return jsonResponse(201, { data: { support_code: 'WF-OWN1', status: 'open' } });
      }

      return jsonResponse(200, { data: {} });
    },
  });

  return { client, storage, requests };
}

test('bootstrap re-reads the shared token rather than minting a second session', async () => {
  // Constructed while storage held nothing: this client captured an empty
  // token, which is the state both tabs are in when two of them come up
  // together.
  const storage = memoryStorage();
  const { client, requests } = clientForOwnership({ storage });

  // Another tab gets there first.
  storage.setItem(TOKEN_KEY, 'token-from-the-other-tab');

  await client.bootstrap(null, null);

  const bootstrapRequest = requests.find((r) => r.url.endsWith('/api/widget/bootstrap'));

  assert.equal(
    bootstrapRequest.body.visitor_token,
    'token-from-the-other-tab',
    'Bootstrapping on a stale in-memory null mints a SECOND session; the support code is one site-wide key, so only one of the two can reach the conversation stored under it.'
  );
});

test('opening a conversation re-asserts the token that owns it', async () => {
  const storage = memoryStorage();
  const { client } = clientForOwnership({ storage, mintedToken: 'token-mine' });

  await client.bootstrap(null, null);
  assert.equal(storage.getItem(TOKEN_KEY), 'token-mine');

  // A tab that came up later overwrites the shared credential. Its session did
  // not open what this tab is about to open.
  storage.setItem(TOKEN_KEY, 'token-theirs');

  await client.startConversation('Hello?', {});

  assert.equal(
    storage.getItem(TOKEN_KEY),
    'token-mine',
    'The support code about to be stored names THIS session, so the token stored beside it has to as well.'
  );
});

test('a tab that minted its own session joins the sibling that got there first', async () => {
  // Both tabs dispatch while storage is empty, so re-reading beforehand cannot
  // help: each would adopt its own answer, and the last one to land would
  // overwrite a shared token the other tab's conversation depends on.
  const storage = memoryStorage();
  const { client, requests } = clientForOwnership({
    storage,
    mintedToken: 'token-mine',
    siblingStoresWhileInFlight: 'token-sibling',
  });

  await client.bootstrap(null, null);

  assert.equal(
    storage.getItem(TOKEN_KEY),
    'token-sibling',
    'Two sessions in one browser means whichever the single site-wide support code does not belong to cannot reach it; converging on the first one stored is what keeps the pair coherent.'
  );

  // And the tab actually USES the joined session rather than merely leaving it
  // in storage while posting under its own -- which would put the conversation
  // on the session the stored token does not name, reintroducing the same split.
  await client.startConversation('Hello?', {});

  const create = requests.find((r) => r.url.endsWith('/api/conversations'));

  assert.equal(
    create.body.visitor_token,
    'token-sibling',
    'The conversation must be opened under the session this tab joined, not the one it discarded.'
  );
});

test('a refused conversation create recovers through bootstrap and retries', async () => {
  // The shape a `createClient()` integration is in after an upgrade: a restored
  // token that predates sessions, no refresh timer, and `sendFirstMessage()`
  // skipping bootstrap because the token is truthy. Without recovery here it
  // posts the refused token forever.
  const storage = memoryStorage({ [TOKEN_KEY]: 'token-pre-session' });
  const { client, requests } = clientForOwnership({
    storage,
    mintedToken: 'token-upgraded',
    refuseUntilBootstrapped: true,
  });

  const conversation = await client.startConversation('Hello?', {});

  assert.equal(conversation.support_code, 'WF-OWN1', 'The create must succeed after recovering.');

  const creates = requests.filter((r) => r.url.endsWith('/api/conversations'));
  const bootstraps = requests.filter((r) => r.url.endsWith('/api/widget/bootstrap'));

  assert.equal(bootstraps.length, 1, 'Recovery is a bootstrap, and exactly one.');
  assert.equal(creates.length, 2, 'One refused attempt, then one retry.');
  assert.equal(
    creates[1].body.visitor_token,
    'token-upgraded',
    'The retry has to carry the NEW token; a payload captured once would post the refused one again.'
  );
});

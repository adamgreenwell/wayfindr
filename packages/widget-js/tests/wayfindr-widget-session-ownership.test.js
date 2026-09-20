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

  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_own',
    anonymousId: 'anon-own',
    storage,
    fetch: async (url, init) => {
      requests.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.endsWith('/api/widget/bootstrap')) {
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

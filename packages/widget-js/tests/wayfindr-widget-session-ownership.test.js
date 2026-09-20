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
const OWNER_KEY = 'wayfindr:site_public_own:visitor-token-owner';

function jsonResponse(status, payload) {
  return { ok: status >= 200 && status < 300, status, json: async () => payload };
}

function memoryStorage(seed, refuseKeySuffix) {
  const values = new Map(Object.entries(seed || {}));

  return {
    getItem: (key) => (values.has(key) ? values.get(key) : null),
    setItem: (key, value) => {
      // One key refused while the others are accepted, the way private browsing
      // or a quota can refuse a single write. Two keys describing one fact can
      // therefore be torn apart, which is the case the removal below exists for.
      if (refuseKeySuffix && key.endsWith(refuseKeySuffix)) {
        throw new Error('storage refused ' + key);
      }

      return values.set(key, value);
    },
    removeItem: (key) => values.delete(key),
  };
}

function clientForOwnership(options) {
  options = options || {};

  const storage = options.storage || memoryStorage();
  const requests = [];
  let bootstrapped = false;
  let sibling = false;
  let bootstrapCalls = 0;
  let release = () => {};
  const held = new Promise((resolve) => {
    release = resolve;
  });

  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_own',
    anonymousId: options.anonymousId || 'anon-own',
    storage,
    fetch: async (url, init) => {
      requests.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.endsWith('/api/widget/bootstrap')) {
        bootstrapped = true;

        // Captured locally. The counter is shared, and the superseding call below
        // increments it before this invocation reaches its own check -- so
        // reading the shared value there made the FIRST call hold too, and
        // nothing ever answered.
        const call = ++bootstrapCalls;

        // A concurrent panel reopen (or a host calling bootstrap() directly)
        // starts AFTER this one and is STILL IN FLIGHT when it lands. The later
        // call takes the ticket the moment it is made, so this response is
        // discarded unadopted -- and the token that will eventually replace it
        // has not arrived yet. Not awaited: it is meant to stay pending.
        if (call === 1 && options.supersededBy) {
          options.supersededBy();
        }

        if (call === 1 && options.duringBootstrap) {
          await options.duringBootstrap();
        }

        // Every call after the first is held until the test releases it, which is
        // what keeps its adoption from happening before the recovery decides.
        // Released rather than abandoned, so the runner can exit.
        if (call > 1) {
          await held;
        }

        // A sibling tab comes up while our request is in flight, which is the
        // race that leaves the pair naming two sessions. It is a REAL client on
        // the same storage, not a hand-written token: what it records is part of
        // what is being tested, and a fixture that wrote only the token would
        // pass a guard the product would fail.
        // A token written with no record beside it, which is what an OLDER widget
        // leaves: it stores the credential and knows nothing about recording
        // whose it is.
        if (options.bareTokenWhileInFlight && !sibling) {
          sibling = true;
          storage.setItem(TOKEN_KEY, options.bareTokenWhileInFlight);
        }

        if (options.siblingWhileInFlight && !sibling) {
          sibling = true;
          await clientForOwnership({
            storage,
            anonymousId: options.siblingWhileInFlight.anonymousId,
            mintedToken: options.siblingWhileInFlight.token,
          }).client.bootstrap(null, null);

          // ...and then an older widget overwrites only the token, leaving the
          // sibling's record naming one that is no longer there.
          if (options.thenOverwriteTokenOnly) {
            storage.setItem(TOKEN_KEY, options.thenOverwriteTokenOnly);
          }
        }

        return jsonResponse(200, {
          data: {
            site: { public_key: 'site_public_own', settings: {} },
            visitor: {
              anonymous_id: options.anonymousId || 'anon-own',
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

  return { client, storage, requests, release };
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
    siblingWhileInFlight: { anonymousId: 'anon-own', token: 'token-sibling' },
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

test('a sibling token that names another visitor is not joined', async () => {
  // `createClient()` takes an explicit `anonymousId`, and the token key is scoped
  // to the SITE -- so two clients on one page can share it while naming different
  // visitors. Joining across that boundary would pair our own `anonymous_id` with
  // a foreign credential, which the server refuses with a terminal 403 that the
  // 401 recovery deliberately does not cover.
  const storage = memoryStorage();
  const { client, requests } = clientForOwnership({
    storage,
    mintedToken: 'token-mine',
    siblingWhileInFlight: { anonymousId: 'anon-somebody-else', token: 'token-theirs' },
  });

  await client.bootstrap(null, null);

  assert.equal(
    storage.getItem(TOKEN_KEY),
    'token-mine',
    'A token we cannot attribute to this visitor must not be joined, and the session the server minted for us is kept instead.'
  );

  await client.startConversation('Hello?', {});

  const create = requests.find((r) => r.url.endsWith('/api/conversations'));

  assert.equal(create.body.visitor_token, 'token-mine');
});

test('a shared token that cannot be attributed at all is not joined', async () => {
  // Two widget versions in one browser is a real, if transient, state: the older
  // one writes a token and no record of whose it is.
  const storage = memoryStorage();
  const { client, requests } = clientForOwnership({
    storage,
    mintedToken: 'token-mine',
    bareTokenWhileInFlight: 'token-unattributable',
  });

  await client.bootstrap(null, null);
  await client.startConversation('Hello?', {});

  // What the client USES is the question. Storage is not: this test writes to it
  // too, so whichever write lands last would answer about the test rather than
  // the product.
  const create = requests.find((r) => r.url.endsWith('/api/conversations'));

  assert.equal(
    create.body.visitor_token,
    'token-mine',
    'Cannot tell whose it is means do not join it; the session the server minted for us is used.'
  );
});

test('a stale owner record does not vouch for the token now in storage', async () => {
  // The other half of the same hazard: a newer widget records an owner, an older
  // one then overwrites only the token, and the record is left naming a token
  // that is no longer there.
  const storage = memoryStorage();
  const { client, requests } = clientForOwnership({
    storage,
    mintedToken: 'token-mine',
    siblingWhileInFlight: { anonymousId: 'anon-own', token: 'token-sibling' },
    thenOverwriteTokenOnly: 'token-from-an-older-widget',
  });

  await client.bootstrap(null, null);
  await client.startConversation('Hello?', {});

  const create = requests.find((r) => r.url.endsWith('/api/conversations'));

  assert.equal(
    create.body.visitor_token,
    'token-mine',
    'A record that names a different token vouches for nothing.'
  );
});

test('a superseded recovery does not retry with the refused token', async () => {
  // The recovery bootstrap is overtaken by a later one, so its answer comes back
  // unadopted. Retrying on the strength of that response would post the very
  // credential the server just refused.
  const storage = memoryStorage({ [TOKEN_KEY]: 'token-pre-session' });
  let harness = null;

  harness = clientForOwnership({
    storage,
    mintedToken: 'token-upgraded',
    refuseUntilBootstrapped: true,
    // Started, deliberately not awaited: it takes the ticket and then hangs.
    supersededBy: () => {
      harness.client.bootstrap(null, null).catch(() => {});
    },
  });

  await assert.rejects(
    () => harness.client.startConversation('Hello?', {}),
    (error) => error.status === 401,
    'With nothing adopted, the caller hears the original refusal rather than a second doomed request.'
  );

  const creates = harness.requests.filter((r) => r.url.endsWith('/api/conversations'));

  assert.equal(
    creates.length,
    1,
    'One refused attempt and no retry: a bootstrap whose answer was discarded is not a recovery.'
  );

  harness.release();
});

test('a conversation re-asserts the record that vouches for its token, not just the token', async () => {
  // The exact race: a client asks for a session of its own, and while its request
  // is in flight two other clients for the same visitor store their pairs and one
  // of them opens a conversation. That conversation's token has to be the one the
  // record vouches for, or the waiting client refuses to join a session that is
  // genuinely its visitor's and overwrites the credential the support code needs.
  const storage = memoryStorage();

  const owner = clientForOwnership({ storage, mintedToken: 'token-owner' });
  const other = clientForOwnership({ storage, mintedToken: 'token-other' });

  const waiting = clientForOwnership({
    storage,
    mintedToken: 'token-waiting',
    duringBootstrap: async () => {
      await owner.client.bootstrap(null, null);
      // A second client for the same visitor replaces the pair...
      await other.client.bootstrap(null, null);
      // ...and then the first one opens a conversation, re-asserting its own.
      await owner.client.startConversation('Hello?', {});
    },
  });

  await waiting.client.bootstrap(null, null);
  await waiting.client.startConversation('Me too?', {});

  const create = waiting.requests.find((r) => r.url.endsWith('/api/conversations'));

  assert.equal(
    create.body.visitor_token,
    'token-owner',
    'The waiting client must join the session the stored record vouches for; a record left naming another token makes it keep its own and overwrite the credential.'
  );
});

test('a torn pair vouches for nothing', async () => {
  // The token stores and the record does not. Keeping the record would leave it
  // describing whatever was there before -- and a record that lies is worse than
  // none, because the whole point of it is to be trusted.
  const storage = memoryStorage({}, ':visitor-token-owner');
  const { client } = clientForOwnership({ storage, mintedToken: 'token-mine' });

  await client.bootstrap(null, null);

  assert.equal(storage.getItem(TOKEN_KEY), 'token-mine');
  assert.equal(storage.getItem(OWNER_KEY), null, 'A record that could not be written must not be left behind.');

  // And behaviourally: a client asking for its own session will not join it.
  const storageB = memoryStorage({}, ':visitor-token-owner');
  const waiting = clientForOwnership({
    storage: storageB,
    mintedToken: 'token-waiting',
    duringBootstrap: async () => {
      await clientForOwnership({ storage: storageB, mintedToken: 'token-unvouched' })
        .client.bootstrap(null, null);
    },
  });

  await waiting.client.bootstrap(null, null);
  await waiting.client.startConversation('Hello?', {});

  const create = waiting.requests.find((r) => r.url.endsWith('/api/conversations'));

  assert.equal(create.body.visitor_token, 'token-waiting');
});

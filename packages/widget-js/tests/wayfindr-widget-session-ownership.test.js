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
  let tokenReads = 0;
  let interpose = null;

  return {
    // Act just before the Nth read of the token key. Counting reads is the only
    // way to land inside a specific time-of-check/time-of-use gap from a
    // single-threaded runtime: a real sibling publishes asynchronously, which
    // lands between whole turns rather than between two adjacent statements.
    interposeBeforeTokenRead: (n, fn) => {
      // Counted from HERE, not from the storage's creation: other clients may
      // already have read it, and an absolute index would drift with them.
      tokenReads = 0;
      interpose = { n, fn };
    },
    tokenReadsSinceInterpose: () => tokenReads,
    getItem: (key) => {
      if (key.endsWith(':visitor-token')) {
        tokenReads += 1;

        if (interpose && interpose.n === tokenReads) {
          const act = interpose.fn;
          interpose = null;
          act();
        }
      }

      return values.has(key) ? values.get(key) : null;
    },
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
    visitorToken: options.visitorToken,
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

test('two tabs holding the same dead token converge instead of splitting', async () => {
  // The upgrade case, and the most reachable of these races: at upgrade EVERY
  // tab holds a token minted before sessions were identified, so every tab
  // presents something truthy that cannot continue a session. Each gets a new
  // and different one. Keying convergence on "did I dispatch without a token"
  // missed this entirely -- both tabs have one.
  const storage = memoryStorage({ [TOKEN_KEY]: 'token-pre-session' });

  const faster = clientForOwnership({ storage, mintedToken: 'token-faster' });

  const slower = clientForOwnership({
    storage,
    mintedToken: 'token-slower',
    duringBootstrap: async () => {
      await faster.client.bootstrap(null, null);
      await faster.client.startConversation('Hello?', {});
    },
  });

  await slower.client.bootstrap(null, null);
  await slower.client.startConversation('Me too?', {});

  const create = slower.requests.find((r) => r.url.endsWith('/api/conversations'));

  assert.equal(
    create.body.visitor_token,
    'token-faster',
    'The slower tab must join the session that already owns the stored support code, not overwrite the credential it needs.'
  );
});

test('a token left in storage before we dispatched does not beat our own', async () => {
  // The other side of the same rule. A value that was already there is not a
  // sibling's answer -- it can be the very token we are replacing -- so adopting
  // it would undo a rotation we were told to make.
  //
  // The stale token is written by a REAL earlier client, so it carries a valid
  // record naming this visitor. Seeding a bare token instead would be refused by
  // the attribution check, and this test would pass without ever reaching the
  // rule it is about.
  const storage = memoryStorage();

  await clientForOwnership({ storage, mintedToken: 'token-stale' }).client.bootstrap(null, null);
  assert.equal(storage.getItem(TOKEN_KEY), 'token-stale');

  // A later client restores it, presents it, and is given a replacement.
  const { client, requests } = clientForOwnership({ storage, mintedToken: 'token-fresh' });

  await client.bootstrap(null, null);
  await client.startConversation('Hello?', {});

  const create = requests.find((r) => r.url.endsWith('/api/conversations'));

  assert.equal(
    create.body.visitor_token,
    'token-fresh',
    'Adopting a token that was already in storage would undo the rotation the server just made.'
  );
  assert.equal(storage.getItem(TOKEN_KEY), 'token-fresh');
});

test('a sibling that publishes in the gap is not overwritten', async () => {
  // The decision to keep our own session is taken against what storage held a
  // moment earlier. If a sibling publishes between that read and the write, a
  // decision already made would overwrite a token newer than ours, and the
  // support code would then belong to the sibling while the credential named us.
  const storage = memoryStorage();

  // A real sibling first, so its pair is what a sibling actually writes rather
  // than what this test imagines. Its values are then replayed synchronously
  // inside the gap, which is the only place they can land between two adjacent
  // statements.
  await clientForOwnership({ storage, mintedToken: 'token-sibling' }).client.bootstrap(null, null);

  const siblingToken = storage.getItem(TOKEN_KEY);
  const siblingOwner = storage.getItem(OWNER_KEY);

  storage.removeItem(TOKEN_KEY);
  storage.removeItem(OWNER_KEY);

  const { client, requests } = clientForOwnership({ storage, mintedToken: 'token-mine' });

  // Reads of the token key during one bootstrap, in order: the re-read before
  // dispatch, the convergence read, and then the check the write itself makes.
  // The sibling lands just before that last one, which is precisely the gap.
  //
  // This index tracks the implementation, so the read count is asserted below --
  // when a change adds or removes a read, this test must FAIL rather than quietly
  // interpose somewhere harmless and go on passing. It has already drifted once.
  const GAP_READ = 3;

  storage.interposeBeforeTokenRead(GAP_READ, () => {
    storage.setItem(TOKEN_KEY, siblingToken);
    storage.setItem(OWNER_KEY, siblingOwner);
  });

  await client.bootstrap(null, null);

  assert.equal(
    storage.tokenReadsSinceInterpose(),
    // Six on THIS path, not the five a bootstrap that wins the publication makes:
    // losing it costs one more read to see what actually landed.
    6,
    'The bootstrap read the token key a different number of times, so GAP_READ no longer names the gap. Re-measure it.'
  );

  await client.startConversation('Hello?', {});

  const create = requests.find((r) => r.url.endsWith('/api/conversations'));

  assert.equal(
    create.body.visitor_token,
    siblingToken,
    'A publication that lost the race must converge on what is actually stored, not overwrite it.'
  );
});

test('two visitors whose ids collide under the fingerprint are still told apart', async () => {
  // `Aa` and `BB` hash to the same value under `visitorTokenFingerprint` -- it is
  // 32 bits and not collision-proof. Attribution decided on a collision joins
  // another visitor's session, and the request that follows pairs their token
  // with our id, which the server refuses with a terminal 403 that conversation
  // creation deliberately does not recover from.
  //
  // Generated ids never collide in practice; `createClient()` takes an explicit
  // one, which is how short values reach this at all.
  const storage = memoryStorage();

  const other = clientForOwnership({ storage, anonymousId: 'Aa', mintedToken: 'token-theirs' });

  const mine = clientForOwnership({
    storage,
    anonymousId: 'BB',
    mintedToken: 'token-mine',
    duringBootstrap: async () => {
      await other.client.bootstrap(null, null);
    },
  });

  await mine.client.bootstrap(null, null);
  await mine.client.startConversation('Hello?', {});

  const create = mine.requests.find((r) => r.url.endsWith('/api/conversations'));

  assert.equal(
    create.body.anonymous_id,
    'BB',
    'Sanity: this client speaks for its own visitor.'
  );
  assert.equal(
    create.body.visitor_token,
    'token-mine',
    'A token belonging to a different visitor must not be joined just because their ids hash alike.'
  );
});

test('an anonymous id containing the record delimiter is compared whole', async () => {
  // A host can pass anything as an explicit id, the delimiter included. Splitting
  // on every delimiter would compare a truncated id -- which either refuses a
  // token that is ours, or worse, matches a different visitor whose id shares the
  // prefix.
  const storage = memoryStorage();

  const sibling = clientForOwnership({ storage, anonymousId: 'tenant|7', mintedToken: 'token-sibling' });

  const mine = clientForOwnership({
    storage,
    anonymousId: 'tenant|7',
    mintedToken: 'token-mine',
    duringBootstrap: async () => {
      await sibling.client.bootstrap(null, null);
    },
  });

  await mine.client.bootstrap(null, null);
  await mine.client.startConversation('Hello?', {});

  const create = mine.requests.find((r) => r.url.endsWith('/api/conversations'));

  assert.equal(
    create.body.visitor_token,
    'token-sibling',
    'The same visitor, so their session is joined -- which only happens if the id survived the round trip through the record intact.'
  );
});

test('a sibling that published before we dispatched is joined too', async () => {
  // Sequential, not concurrent: tab A upgraded its token and stored the
  // replacement, and tab B only then dispatches -- still holding the old token in
  // memory, so it never re-read A's. Comparing against what storage held at
  // dispatch called that "unchanged" and let B publish a second session, which
  // strands any conversation A had opened. Comparing against what we PRESENTED
  // sees it for what it is: somebody else's token, already there.
  const storage = memoryStorage();

  const upgraded = clientForOwnership({ storage, mintedToken: 'token-upgraded' });
  await upgraded.client.bootstrap(null, null);
  await upgraded.client.startConversation('Hello?', {});

  // B is constructed while the old token is what storage holds...
  const stale = memoryStorage({ [TOKEN_KEY]: 'token-pre-session' });
  const behind = clientForOwnership({ storage: stale, mintedToken: 'token-behind' });

  // ...and by the time it bootstraps, A's pair is what is actually stored.
  stale.setItem(TOKEN_KEY, storage.getItem(TOKEN_KEY));
  stale.setItem(OWNER_KEY, storage.getItem(OWNER_KEY));

  await behind.client.bootstrap(null, null);
  await behind.client.startConversation('Me too?', {});

  const create = behind.requests.find((r) => r.url.endsWith('/api/conversations'));

  assert.equal(
    create.body.visitor_token,
    'token-upgraded',
    'A token already in storage that is not the one we presented belongs to a sibling, whenever it got there.'
  );
});

test('a token the host supplied is never traded for a sibling session', async () => {
  // `createClient()` is handed a token deliberately, and it may be the session
  // that owns the host's conversation. Joining a sibling would abandon it, and
  // there is no way to ask which of the two was wanted.
  const storage = memoryStorage();

  const sibling = clientForOwnership({ storage, mintedToken: 'token-sibling' });
  await sibling.client.bootstrap(null, null);

  const hosted = clientForOwnership({
    storage,
    mintedToken: 'token-minted',
    visitorToken: 'token-from-the-host',
  });

  await hosted.client.bootstrap(null, null);

  const bootstrapRequest = hosted.requests.find((r) => r.url.endsWith('/api/widget/bootstrap'));

  assert.equal(bootstrapRequest.body.visitor_token, 'token-from-the-host');

  await hosted.client.startConversation('Hello?', {});

  const create = hosted.requests.find((r) => r.url.endsWith('/api/conversations'));

  assert.equal(
    create.body.visitor_token,
    'token-minted',
    'The replacement the server minted for the host token is used -- not the sibling session in storage.'
  );
});

test('a host token the server refuses stops exempting the client from converging', async () => {
  // Two widgets handed the SAME explicit token, expired or minted before session
  // ids. The exemption rests on that token possibly still naming the session that
  // owns the host's conversation -- and a 401 is proof it does not, so holding the
  // exemption past that point protects nothing while keeping the two tabs on two
  // sessions.
  const storage = memoryStorage();

  const first = clientForOwnership({
    storage,
    visitorToken: 'token-from-the-host',
    mintedToken: 'token-first',
    refuseUntilBootstrapped: true,
  });

  await first.client.startConversation('Hello?', {});

  // The first tab recovered and published. The second is still holding the same
  // dead host token.
  const second = clientForOwnership({
    storage,
    visitorToken: 'token-from-the-host',
    mintedToken: 'token-second',
    refuseUntilBootstrapped: true,
  });

  await second.client.startConversation('Me too?', {});

  const creates = second.requests.filter((r) => r.url.endsWith('/api/conversations'));

  assert.equal(
    creates[creates.length - 1].body.visitor_token,
    'token-first',
    'Once refused, the host token proves nothing, so the second tab joins the session already in storage instead of minting a rival.'
  );
});

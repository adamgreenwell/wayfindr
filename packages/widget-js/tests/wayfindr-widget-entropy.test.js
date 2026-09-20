const assert = require('node:assert/strict');
const test = require('node:test');

const Wayfindr = require('../src/wayfindr-widget.js');

// The anonymous id is what bootstrap mints a visitor session from, together with
// the site's public key -- both values Wayfindr publishes or displays. An id
// anyone can guess is therefore a session anyone can mint, so the id is either
// unguessable or the widget does not start.

function memoryStorage() {
  const values = new Map();

  return {
    getItem: (k) => (values.has(k) ? values.get(k) : null),
    setItem: (k, v) => values.set(k, v),
    removeItem: (k) => values.delete(k),
  };
}

// `randomToken()` reads `root.crypto` at CALL time, so a test can decide what the
// environment offers. `root` is `globalThis` here, because the module is loaded
// before any JSDOM window exists.
function withCrypto(replacement, run) {
  const real = globalThis.crypto;

  Object.defineProperty(globalThis, 'crypto', { value: replacement, configurable: true });

  try {
    return run();
  } finally {
    Object.defineProperty(globalThis, 'crypto', { value: real, configurable: true });
  }
}

function clientWith(storage) {
  return Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    storage,
    fetch: async () => ({ ok: true, status: 200, json: async () => ({ data: {} }) }),
  });
}

test('an anonymous id is unguessable on a page where randomUUID does not exist', () => {
  // The case the weak fallback existed for: `randomUUID` is secure-context only,
  // so on a plain http:// page it is absent. `getRandomValues` is the one member
  // of Crypto available there, which is why failing closed costs nothing here.
  const real = globalThis.crypto;

  const id = withCrypto({ getRandomValues: real.getRandomValues.bind(real) }, () => {
    return clientWith(memoryStorage()).anonymousId;
  });

  assert.match(
    id,
    /^anon_[0-9a-f]{32}$/,
    'Without randomUUID the id must still be 128 bits of hex, not Math.random in base36.'
  );
});

test('every byte contributes two hex digits, including the low ones', () => {
    // Pinned with a deterministic source rather than a random one. A bare
    // `toString(16)` drops the leading zero on any byte below 0x10, which
    // shortens the id and quietly costs it entropy -- and with real randomness
    // that only shows up when such a byte happens to be drawn, so the obvious
    // regex assertion above catches it roughly two runs in three. This one
    // catches it every time.
  const lowBytes = {
    getRandomValues: (array) => {
      for (let i = 0; i < array.length; i += 1) {
        array[i] = 0x05;
      }

      return array;
    },
  };

  const id = withCrypto(lowBytes, () => clientWith(memoryStorage()).anonymousId);

  assert.equal(id, 'anon_' + '05'.repeat(16));
});

test('two ids from the insecure-context path do not collide', () => {
  const real = globalThis.crypto;
  const seen = new Set();

  withCrypto({ getRandomValues: real.getRandomValues.bind(real) }, () => {
    for (let i = 0; i < 50; i += 1) {
      seen.add(clientWith(memoryStorage()).anonymousId);
    }
  });

  assert.equal(seen.size, 50);
});

test('an environment with no strong source gets no widget at all', () => {
  // Fails closed rather than minting a guessable identity. Unreachable in
  // practice: `createClient` already refuses without `fetch`, which shipped
  // years after `getRandomValues`, so a browser that could reach this throw
  // could never have run the widget.
  assert.throws(
    () => withCrypto({}, () => clientWith(memoryStorage())),
    /secure random source/,
    'A weak identity is worse than no widget: it is a session anyone can mint.'
  );
});

test('a stored id is reused rather than regenerated', () => {
  // The refusal must not fire for a visitor who already has an id, or an
  // environment that degrades would strand the people it had already served.
  const storage = memoryStorage();
  const first = clientWith(storage).anonymousId;

  const second = withCrypto({}, () => clientWith(storage).anonymousId);

  assert.equal(second, first);
});

test('a host-supplied id is used as given, strong source or not', () => {
  const supplied = withCrypto({}, () => Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'tenant-supplied-identity',
    storage: memoryStorage(),
    fetch: async () => ({ ok: true, status: 200, json: async () => ({ data: {} }) }),
  }).anonymousId);

  assert.equal(
    supplied,
    'tenant-supplied-identity',
    'The widget cannot judge a host id, which is why the requirement that it be unguessable is documented rather than enforced.'
  );
});

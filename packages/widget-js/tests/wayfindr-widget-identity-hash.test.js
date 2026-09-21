const assert = require('node:assert/strict');
const test = require('node:test');

const Wayfindr = require('../src/wayfindr-widget.js');

// A host that turns on identity verification computes an HMAC of its own
// customer id ON ITS OWN SERVER and hands the widget the result. The widget
// never computes it and never holds the secret -- a hash computed in page
// JavaScript would ship the secret to everyone who views source, which is the
// whole thing verification exists to prevent.
//
// So the widget's entire job is to carry an opaque string beside the id it
// vouches for. These tests are about that pairing.

function jsonResponse(status, payload) {
  return { ok: status >= 200 && status < 300, status, json: async () => payload };
}

function memoryStorage() {
  const values = new Map();

  return {
    getItem: (key) => (values.has(key) ? values.get(key) : null),
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
  };
}

function identityClient(options) {
  const requests = [];

  const client = Wayfindr.createClient(Object.assign({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_id',
    anonymousId: 'anon-id',
    visitorToken: 'visitor-token-id',
    storage: memoryStorage(),
    fetch: async (url, init) => {
      requests.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: {
            site: { public_key: 'site_public_id', settings: {}, intake: { asks: false, fields: {} } },
            visitor: { anonymous_id: 'anon-id', token: 'visitor-token-id', identified: true },
          },
        });
      }

      if (url.endsWith('/api/conversations')) {
        return jsonResponse(201, {
          data: {
            support_code: 'WF-ID-1',
            status: 'open',
            subject: 'Hello',
            messages: [],
            visitor: { anonymous_id: 'anon-id' },
          },
        });
      }

      return jsonResponse(200, { data: {} });
    },
  }, options));

  return { client, requests };
}

function bodyFor(requests, suffix) {
  const entry = requests.find((r) => r.url.endsWith(suffix));

  assert.ok(entry, 'expected a request to ' + suffix);

  return entry.body;
}

test('the hash is sent beside the id it vouches for', async () => {
  const { client, requests } = identityClient({
    visitorExternalId: 'customer-123',
    visitorIdentityHash: 'a'.repeat(64),
  });

  await client.bootstrap(null, null);

  const body = bodyFor(requests, '/api/widget/bootstrap');

  assert.equal(body.external_id, 'customer-123');
  assert.equal(body.identity_hash, 'a'.repeat(64));
});

test('a hash with no id is not sent at all', async () => {
  const { client, requests } = identityClient({ visitorIdentityHash: 'a'.repeat(64) });

  await client.bootstrap(null, null);

  const body = bodyFor(requests, '/api/widget/bootstrap');

  // A hash names nothing on its own. Sending it would put a signature into an
  // access log to no purpose.
  assert.equal(body.external_id, undefined);
  assert.equal(body.identity_hash, undefined);
});

test('an id with no hash is still sent, for sites not using verification', async () => {
  const { client, requests } = identityClient({ visitorExternalId: 'customer-123' });

  await client.bootstrap(null, null);

  const body = bodyFor(requests, '/api/widget/bootstrap');

  // Verification is per-site and off by default. A widget that withheld the id
  // unless it had a hash would un-identify every existing install.
  assert.equal(body.external_id, 'customer-123');
  assert.equal(body.identity_hash, undefined);
});

test('the hash reaches the conversation endpoint too', async () => {
  const { client, requests } = identityClient({
    visitorExternalId: 'customer-123',
    visitorIdentityHash: 'b'.repeat(64),
  });

  await client.bootstrap(null, null);
  await client.startConversation('Hello', {});

  const body = bodyFor(requests, '/api/conversations');

  // The server verifies on BOTH write paths, so a widget that only signed the
  // bootstrap would leave the conversation path unable to identify anyone.
  assert.equal(body.external_id, 'customer-123');
  assert.equal(body.identity_hash, 'b'.repeat(64));
});

test('a per-conversation id override does not reuse the init-time hash', async () => {
  const { client, requests } = identityClient({
    visitorExternalId: 'customer-123',
    visitorIdentityHash: 'c'.repeat(64),
  });

  await client.bootstrap(null, null);
  await client.startConversation('Hello', { visitorExternalId: 'customer-999' });

  const body = bodyFor(requests, '/api/conversations');

  // THE PAIRING RULE. A hash minted for `customer-123` does not vouch for
  // `customer-999`. Carrying it over would send a signature for a different
  // subject -- refused by a verifying site, and misleading to read in a log.
  assert.equal(body.external_id, 'customer-999');
  assert.equal(body.identity_hash, undefined);
});

test('a per-conversation override carries its own hash', async () => {
  const { client, requests } = identityClient({
    visitorExternalId: 'customer-123',
    visitorIdentityHash: 'c'.repeat(64),
  });

  await client.bootstrap(null, null);
  await client.startConversation('Hello', {
    visitorExternalId: 'customer-999',
    visitorIdentityHash: 'd'.repeat(64),
  });

  const body = bodyFor(requests, '/api/conversations');

  assert.equal(body.external_id, 'customer-999');
  assert.equal(body.identity_hash, 'd'.repeat(64));
});

test('a blank hash is treated as absent rather than sent empty', async () => {
  const { client, requests } = identityClient({
    visitorExternalId: 'customer-123',
    visitorIdentityHash: '   ',
  });

  await client.bootstrap(null, null);

  const body = bodyFor(requests, '/api/widget/bootstrap');

  assert.equal(body.external_id, 'customer-123');
  assert.equal(body.identity_hash, undefined);
});

const assert = require('node:assert/strict');
const test = require('node:test');

const Wayfindr = require('../src/wayfindr-widget.js');

// A stand-in for pusher-js that enforces the one rule the real library
// enforces at construction: a cluster must be present, even when wsHost names
// the server outright. Without it the real library throws "Options object must
// provide a cluster", which is what broke every widget realtime connection.
function StrictPusher(key, options) {
  if (!Object.prototype.hasOwnProperty.call(options || {}, 'cluster')) {
    throw new Error('Options object must provide a cluster');
  }

  StrictPusher.lastOptions = options;

  this.subscribe = () => ({ bind: () => {}, unbind: () => {} });
  this.connection = { bind: () => {}, unbind: () => {} };
  this.unsubscribe = () => {};
  this.disconnect = () => {};
}

test('the realtime client is constructed with a cluster so pusher-js accepts it', () => {
  const realtime = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-docs',
    fetch: async () => ({ ok: true, status: 200, json: async () => ({ data: {} }) }),
    visitorToken: 'visitor-token-123',
    Pusher: StrictPusher,
    reverb: {
      appKey: 'app-key-123',
      host: '10.211.55.6',
      port: 443,
      scheme: 'https',
    },
  });

  // Throws without the fix, exactly as the real library does.
  const subscription = realtime.subscribeToConversation('WF-TEST123', () => {}, () => {}, () => {});

  assert.ok(subscription, 'a subscription is returned rather than the construction throwing');
  assert.equal(StrictPusher.lastOptions.cluster, '', 'an empty cluster satisfies the check');
  assert.equal(StrictPusher.lastOptions.wsHost, '10.211.55.6', 'the self-hosted server is still the endpoint');
});

test('an explicit cluster is passed through when one is configured', () => {
  const realtime = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-docs',
    fetch: async () => ({ ok: true, status: 200, json: async () => ({ data: {} }) }),
    visitorToken: 'visitor-token-123',
    Pusher: StrictPusher,
    reverb: {
      appKey: 'app-key-123',
      host: 'ws.example.test',
      port: 443,
      scheme: 'https',
      cluster: 'eu',
    },
  });

  realtime.subscribeToConversation('WF-TEST123', () => {}, () => {}, () => {});

  assert.equal(StrictPusher.lastOptions.cluster, 'eu');
});

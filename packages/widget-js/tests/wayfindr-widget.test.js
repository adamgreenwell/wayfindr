const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

test('attaches the public API to window for classic script tags', () => {
  const source = fs.readFileSync(path.join(__dirname, '../src/wayfindr-widget.js'), 'utf8');
  const sandbox = { window: {} };

  vm.runInNewContext(source, sandbox);

  assert.equal(typeof sandbox.window.Wayfindr.init, 'function');
  assert.equal(typeof sandbox.window.Wayfindr.createClient, 'function');
});

test('resolves a stable anonymous id from storage when one is not supplied', () => {
  const values = new Map();
  const storage = {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
  };

  const first = Wayfindr.resolveAnonymousId({
    sitePublicKey: 'site_public_docs',
    storage,
  });
  const second = Wayfindr.resolveAnonymousId({
    sitePublicKey: 'site_public_docs',
    storage,
  });

  assert.equal(first, second);
  assert.match(first, /^anon_/);
});

test('bootstraps the widget against the public intake API', async () => {
  const calls = [];
  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    fetch: async (url, options) => {
      calls.push({ url, options });

      return jsonResponse(201, {
        data: {
          site: {
            public_key: 'site_public_docs',
            settings: {
              mask_selectors: ['input[type="password"]'],
            },
          },
          visitor: {
            anonymous_id: 'anon-browser-123',
            token: 'visitor-token-123',
          },
        },
      });
    },
  });

  const result = await client.bootstrap('https://docs.example.test/install');

  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, 'http://127.0.0.1:8000/api/widget/bootstrap');
  assert.deepEqual(JSON.parse(calls[0].options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    page_url: 'https://docs.example.test/install',
  });
  assert.equal(result.site.public_key, 'site_public_docs');
  assert.equal(result.visitor.token, 'visitor-token-123');
});

test('starts a conversation and sends the first visitor message', async () => {
  const calls = [];
  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    fetch: async (url, options) => {
      calls.push({ url, options });

      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: {
            site: {
              public_key: 'site_public_docs',
              settings: {},
            },
            visitor: {
              anonymous_id: 'anon-browser-123',
              token: 'visitor-token-123',
            },
          },
        });
      }

      if (url.endsWith('/api/conversations')) {
        return jsonResponse(201, {
          data: {
            support_code: 'WF-TEST123',
            status: 'open',
          },
        });
      }

      return jsonResponse(201, {
        data: {
          conversation: {
            support_code: 'WF-TEST123',
          },
          message: {
            type: 'text',
            body: 'Can you help me?',
          },
        },
      });
    },
  });

  const result = await client.sendFirstMessage('Can you help me?', {
    pageUrl: 'https://docs.example.test/install',
  });

  assert.equal(calls.length, 3);
  assert.equal(calls[0].url, 'http://127.0.0.1:8000/api/widget/bootstrap');
  assert.equal(calls[1].url, 'http://127.0.0.1:8000/api/conversations');
  assert.deepEqual(JSON.parse(calls[1].options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    visitor_token: 'visitor-token-123',
    subject: 'Can you help me?',
    page_url: 'https://docs.example.test/install',
  });
  assert.equal(calls[2].url, 'http://127.0.0.1:8000/api/conversations/WF-TEST123/messages');
  assert.deepEqual(JSON.parse(calls[2].options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    visitor_token: 'visitor-token-123',
    body: 'Can you help me?',
  });
  assert.equal(result.conversation.support_code, 'WF-TEST123');
  assert.equal(result.message.body, 'Can you help me?');
});

test('fetches visitor-visible conversation messages', async () => {
  const calls = [];
  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    visitorToken: 'visitor-token-123',
    fetch: async (url, options) => {
      calls.push({ url, options });

      return jsonResponse(200, {
        data: {
          conversation: {
            support_code: 'WF-TEST123',
          },
          messages: [
            {
              id: 1,
              sender: {
                kind: 'agent',
                name: 'Ada Agent',
              },
              type: 'text',
              body: 'Hello from support.',
              created_at: '2026-05-23T13:00:00.000000Z',
            },
          ],
        },
      });
    },
  });

  const result = await client.fetchMessages('WF-TEST123');

  assert.equal(calls.length, 1);
  assert.equal(
    calls[0].url,
    'http://127.0.0.1:8000/api/conversations/WF-TEST123/messages?site_public_key=site_public_docs&anonymous_id=anon-browser-123&visitor_token=visitor-token-123',
  );
  assert.equal(calls[0].options.method, 'GET');
  assert.equal(result.conversation.support_code, 'WF-TEST123');
  assert.equal(result.messages[0].sender.kind, 'agent');
  assert.equal(result.messages[0].body, 'Hello from support.');
});

test('sets cobrowse consent through the public visitor API', async () => {
  const calls = [];
  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    visitorToken: 'visitor-token-123',
    fetch: async (url, options) => {
      calls.push({ url, options });

      return jsonResponse(200, {
        data: {
          conversation: {
            support_code: 'WF-TEST123',
          },
          cobrowse: {
            status: 'granted',
            consent: 'granted',
          },
        },
      });
    },
  });

  const result = await client.setCobrowseConsent('WF-TEST123', true);

  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, 'http://127.0.0.1:8000/api/conversations/WF-TEST123/cobrowse-consent');
  assert.deepEqual(JSON.parse(calls[0].options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    visitor_token: 'visitor-token-123',
    granted: true,
  });
  assert.equal(result.conversation.support_code, 'WF-TEST123');
  assert.equal(result.cobrowse.consent, 'granted');
});

test('reports cobrowse telemetry through the public visitor API', async () => {
  const calls = [];
  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    visitorToken: 'visitor-token-123',
    fetch: async (url, options) => {
      calls.push({ url, options });

      return jsonResponse(200, {
        data: {
          conversation: {
            support_code: 'WF-TEST123',
          },
          cobrowse: {
            status: 'granted',
          },
          telemetry: {
            rtt_ms: 120,
            max_rtt_ms: 184,
            payload_bytes: 2048,
            max_payload_bytes: 8192,
            dropped_batches: 1,
            reconnects: 0,
            samples: 3,
          },
        },
      });
    },
  });

  const result = await client.reportCobrowseTelemetry('WF-TEST123', {
    rttMs: 120,
    payloadBytes: 2048,
    droppedBatches: 1,
    reconnects: 0,
  });

  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, 'http://127.0.0.1:8000/api/conversations/WF-TEST123/cobrowse-telemetry');
  assert.deepEqual(JSON.parse(calls[0].options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    visitor_token: 'visitor-token-123',
    rtt_ms: 120,
    payload_bytes: 2048,
    dropped_batches: 1,
    reconnects: 0,
  });
  assert.equal(result.telemetry.samples, 3);
});

test('prepares private conversation subscriptions for realtime adapters', () => {
  let subscriptionPayload = null;
  const received = [];
  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    visitorToken: 'visitor-token-123',
    fetch: async () => jsonResponse(200, { data: {} }),
    realtime: {
      subscribe: (payload) => {
        subscriptionPayload = payload;

        return {
          unsubscribe: () => {},
        };
      },
    },
  });

  const subscription = client.subscribeToConversation('WF-TEST123', (event) => {
    received.push(event);
  });

  subscriptionPayload.onMessage({
    conversation: { support_code: 'WF-TEST123' },
    message: {
      id: 2,
      sender: { kind: 'agent', name: 'Ada Agent' },
      type: 'text',
      body: 'Live hello.',
      created_at: '2026-05-23T14:01:00.000000Z',
    },
  });

  assert.equal(typeof subscription.unsubscribe, 'function');
  assert.equal(subscriptionPayload.channelName, 'private-conversations.WF-TEST123');
  assert.equal(subscriptionPayload.eventName, 'conversation.message.created');
  assert.equal(subscriptionPayload.authEndpoint, 'http://127.0.0.1:8000/api/widget/broadcasting/auth');
  assert.deepEqual(subscriptionPayload.authPayload, {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    visitor_token: 'visitor-token-123',
  });
  assert.equal(received.length, 1);
  assert.equal(received[0].message.body, 'Live hello.');
});

test('authorizes built-in Pusher subscriptions through the widget endpoint', async () => {
  const calls = [];
  let appKey = null;
  let pusherOptions = null;
  let subscribedChannel = null;
  let unbound = false;
  let unsubscribed = false;
  let disconnected = false;

  function FakePusher(key, options) {
    appKey = key;
    pusherOptions = options;

    this.subscribe = (channelName) => {
      subscribedChannel = channelName;

      return {
        bind: () => {},
        unbind: () => {
          unbound = true;
        },
      };
    };
    this.unsubscribe = () => {
      unsubscribed = true;
    };
    this.disconnect = () => {
      disconnected = true;
    };
  }

  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    visitorToken: 'visitor-token-123',
    Pusher: FakePusher,
    reverb: {
      appKey: 'reverb-key',
      host: 'localhost',
      port: 8080,
      scheme: 'http',
    },
    fetch: async (url, options) => {
      calls.push({ url, options });

      return jsonResponse(200, {
        auth: 'reverb-key:signed-channel',
      });
    },
  });

  const subscription = client.subscribeToConversation('WF-TEST123', () => {});
  const authPayload = await new Promise((resolve, reject) => {
    pusherOptions.channelAuthorization.customHandler({
      socketId: '1234.5678',
      channelName: subscribedChannel,
    }, (error, payload) => {
      if (error) {
        reject(error);

        return;
      }

      resolve(payload);
    });
  });

  assert.equal(appKey, 'reverb-key');
  assert.equal(subscribedChannel, 'private-conversations.WF-TEST123');
  assert.equal(pusherOptions.wsHost, 'localhost');
  assert.equal(pusherOptions.wsPort, 8080);
  assert.equal(pusherOptions.wssPort, 8080);
  assert.equal(pusherOptions.forceTLS, false);
  assert.deepEqual(pusherOptions.enabledTransports, ['ws', 'wss']);
  assert.equal(calls[0].url, 'http://127.0.0.1:8000/api/widget/broadcasting/auth');
  assert.deepEqual(JSON.parse(calls[0].options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    visitor_token: 'visitor-token-123',
    socket_id: '1234.5678',
    channel_name: 'private-conversations.WF-TEST123',
  });
  assert.deepEqual(authPayload, {
    auth: 'reverb-key:signed-channel',
  });

  subscription.unsubscribe();

  assert.equal(unbound, true);
  assert.equal(unsubscribed, true);
  assert.equal(disconnected, true);
});

test('renders the embedded conversation timeline and refreshes replies', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  const calls = [];
  let timelineFetches = 0;

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    storage: memoryStorage(),
    fetch: async (url, options) => {
      calls.push({ url, options });

      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(201, {
          data: {
            site: { public_key: 'site_public_docs', settings: {} },
            visitor: { anonymous_id: 'anon-browser-123', token: 'visitor-token-123' },
          },
        });
      }

      if (url.endsWith('/api/conversations')) {
        return jsonResponse(201, {
          data: {
            support_code: 'WF-TEST123',
            status: 'open',
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/messages')) {
        return jsonResponse(201, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            message: {
              sender: { kind: 'visitor', name: 'Visitor' },
              type: 'text',
              body: 'Can you help me?',
              created_at: '2026-05-23T14:00:00.000000Z',
            },
          },
        });
      }

      timelineFetches += 1;

      return jsonResponse(200, {
        data: {
          conversation: {
            support_code: 'WF-TEST123',
            status: 'open',
          },
          messages: [
            {
              id: 1,
              sender: { kind: 'visitor', name: 'Visitor' },
              type: 'text',
              body: 'Can you help me?',
              created_at: '2026-05-23T14:00:00.000000Z',
            },
            ...(timelineFetches > 1
              ? [{
                  id: 2,
                  sender: { kind: 'agent', name: 'Ada Agent' },
                  type: 'text',
                  body: 'Absolutely, happy to help.',
                  created_at: '2026-05-23T14:01:00.000000Z',
                }]
              : []),
          ],
        },
      });
    },
  });

  widget.open();

  widget.root.querySelector('.wayfindr-widget__textarea').value = 'Can you help me?';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );

  await settle();

  assert.match(
    widget.root.querySelector('.wayfindr-widget__status').textContent,
    /Support code WF-TEST123/,
  );
  assert.deepEqual(JSON.parse(calls.find((call) => call.url.endsWith('/api/conversations')).options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    visitor_token: 'visitor-token-123',
    subject: 'Can you help me?',
    page_url: 'https://docs.example.test/install',
  });
  assert.deepEqual(
    [...widget.root.querySelectorAll('.wayfindr-widget__message')].map((message) => message.textContent),
    ['VisitorCan you help me?'],
  );

  const refresh = widget.root.querySelector('.wayfindr-widget__refresh');

  assert.equal(refresh.hidden, false);

  refresh.dispatchEvent(new dom.window.Event('click', { bubbles: true }));

  await settle();

  assert.deepEqual(
    [...widget.root.querySelectorAll('.wayfindr-widget__message')].map((message) => message.textContent),
    ['VisitorCan you help me?', 'Ada AgentAbsolutely, happy to help.'],
  );
});

test('appends live agent messages from the realtime subscription', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  let liveMessage = null;
  let unsubscribed = false;

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    storage: memoryStorage(),
    realtime: {
      subscribe: ({ onMessage }) => {
        liveMessage = onMessage;

        return {
          unsubscribe: () => {
            unsubscribed = true;
          },
        };
      },
    },
    fetch: async (url) => {
      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(201, {
          data: {
            site: { public_key: 'site_public_docs', settings: {} },
            visitor: { anonymous_id: 'anon-browser-123', token: 'visitor-token-123' },
          },
        });
      }

      if (url.endsWith('/api/conversations')) {
        return jsonResponse(201, {
          data: {
            support_code: 'WF-TEST123',
            status: 'open',
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/messages')) {
        return jsonResponse(201, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            message: {
              id: 1,
              sender: { kind: 'visitor', name: 'Visitor' },
              type: 'text',
              body: 'Can you help me?',
              created_at: '2026-05-23T14:00:00.000000Z',
            },
          },
        });
      }

      return jsonResponse(200, {
        data: {
          conversation: {
            support_code: 'WF-TEST123',
            status: 'open',
          },
          messages: [
            {
              id: 1,
              sender: { kind: 'visitor', name: 'Visitor' },
              type: 'text',
              body: 'Can you help me?',
              created_at: '2026-05-23T14:00:00.000000Z',
            },
          ],
        },
      });
    },
  });

  widget.open();

  widget.root.querySelector('.wayfindr-widget__textarea').value = 'Can you help me?';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );

  await settle();

  liveMessage({
    conversation: { support_code: 'WF-TEST123' },
    message: {
      id: 2,
      sender: { kind: 'agent', name: 'Ada Agent' },
      type: 'text',
      body: 'Live hello.',
      created_at: '2026-05-23T14:01:00.000000Z',
    },
  });

  assert.deepEqual(
    [...widget.root.querySelectorAll('.wayfindr-widget__message')].map((message) => message.textContent),
    ['VisitorCan you help me?', 'Ada AgentLive hello.'],
  );

  widget.destroy();

  assert.equal(unsubscribed, true);
});

test('renders widget cobrowse consent controls after a conversation starts', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  const calls = [];

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    storage: memoryStorage(),
    fetch: async (url, options) => {
      calls.push({ url, options });

      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(201, {
          data: {
            site: { public_key: 'site_public_docs', settings: {} },
            visitor: { anonymous_id: 'anon-browser-123', token: 'visitor-token-123' },
          },
        });
      }

      if (url.endsWith('/api/conversations')) {
        return jsonResponse(201, {
          data: {
            support_code: 'WF-TEST123',
            status: 'open',
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/messages')) {
        return jsonResponse(201, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            message: {
              id: 1,
              sender: { kind: 'visitor', name: 'Visitor' },
              type: 'text',
              body: 'Can you help me?',
              created_at: '2026-05-23T14:00:00.000000Z',
            },
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/cobrowse-consent')) {
        const payload = JSON.parse(options.body);

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: {
              status: payload.granted ? 'granted' : 'revoked',
              consent: payload.granted ? 'granted' : 'revoked',
            },
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/cobrowse-telemetry')) {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: { status: 'granted' },
            telemetry: {
              rtt_ms: 10,
              payload_bytes: 256,
              dropped_batches: 0,
              reconnects: 0,
              samples: 1,
            },
          },
        });
      }

      return jsonResponse(200, {
        data: {
          conversation: { support_code: 'WF-TEST123', status: 'open' },
          messages: [
            {
              id: 1,
              sender: { kind: 'visitor', name: 'Visitor' },
              type: 'text',
              body: 'Can you help me?',
              created_at: '2026-05-23T14:00:00.000000Z',
            },
          ],
        },
      });
    },
  });

  widget.open();

  widget.root.querySelector('.wayfindr-widget__textarea').value = 'Can you help me?';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );

  await settle();

  const cobrowse = widget.root.querySelector('.wayfindr-widget__cobrowse');
  const cobrowseButton = widget.root.querySelector('.wayfindr-widget__cobrowse-toggle');

  assert.equal(cobrowse.hidden, false);
  assert.equal(cobrowseButton.textContent, 'Allow cobrowse');

  cobrowseButton.dispatchEvent(new dom.window.Event('click', { bubbles: true }));

  await settle();

  const grantCall = calls.find((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-consent'));

  assert.deepEqual(JSON.parse(grantCall.options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    visitor_token: 'visitor-token-123',
    granted: true,
  });
  assert.equal(cobrowseButton.textContent, 'Stop cobrowse');
  assert.match(widget.root.querySelector('.wayfindr-widget__status').textContent, /Cobrowse consent granted/);

  const telemetryCall = calls.find((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-telemetry'));
  const telemetryPayload = JSON.parse(telemetryCall.options.body);

  assert.equal(telemetryPayload.site_public_key, 'site_public_docs');
  assert.equal(telemetryPayload.anonymous_id, 'anon-browser-123');
  assert.equal(telemetryPayload.visitor_token, 'visitor-token-123');
  assert.equal(typeof telemetryPayload.rtt_ms, 'number');
  assert.equal(typeof telemetryPayload.payload_bytes, 'number');
  assert.equal(telemetryPayload.dropped_batches, 0);
  assert.equal(telemetryPayload.reconnects, 0);

  cobrowseButton.dispatchEvent(new dom.window.Event('click', { bubbles: true }));

  await settle();

  const revokeCall = calls.filter((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-consent'))[1];

  assert.equal(JSON.parse(revokeCall.options.body).granted, false);
  assert.equal(cobrowseButton.textContent, 'Allow cobrowse');
  assert.match(widget.root.querySelector('.wayfindr-widget__status').textContent, /Cobrowse consent revoked/);
});

function jsonResponse(status, payload) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => payload,
  };
}

async function settle() {
  await new Promise((resolve) => setImmediate(resolve));
  await new Promise((resolve) => setImmediate(resolve));
}

function memoryStorage() {
  const values = new Map();

  return {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
  };
}

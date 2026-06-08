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

test('injects a hidden override for stateful widget sections', () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });

  Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    storage: memoryStorage(),
    fetch: async () => jsonResponse(404, { message: 'Not used' }),
  });

  assert.match(
    dom.window.document.querySelector('#wayfindr-widget-styles').textContent,
    /\.wayfindr-widget \[hidden\]\{display:none!important\}/,
  );
});

test('shows calm empty-state copy before a widget conversation starts', () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    storage: memoryStorage(),
    fetch: async () => jsonResponse(404, { message: 'Not used' }),
  });

  widget.open();

  const timeline = widget.root.querySelector('.wayfindr-widget__timeline');
  const notice = widget.root.querySelector('.wayfindr-widget__notice');

  assert.equal(timeline.hidden, true);
  assert.equal(notice.hidden, false);
  assert.match(notice.textContent, /No messages yet/);
  assert.match(notice.textContent, /Send a message/);
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

  const result = await client.bootstrap('https://docs.example.test/install', {
    plan: 'Team',
    support_region: 'EU',
  });

  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, 'http://127.0.0.1:8000/api/widget/bootstrap');
  assert.deepEqual(JSON.parse(calls[0].options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    page_url: 'https://docs.example.test/install',
    context: {
      plan: 'Team',
      support_region: 'EU',
    },
  });
  assert.equal(result.site.public_key, 'site_public_docs');
  assert.equal(result.visitor.token, 'visitor-token-123');
});

test('bootstraps with a host visitor identifier when configured', async () => {
  const calls = [];
  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    visitorExternalId: 'customer-123',
    fetch: async (url, options) => {
      calls.push({ url, options });

      return jsonResponse(201, {
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
    },
  });

  await client.bootstrap('https://docs.example.test/install', {
    plan: 'Team',
  });

  assert.deepEqual(JSON.parse(calls[0].options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    external_id: 'customer-123',
    page_url: 'https://docs.example.test/install',
    context: {
      plan: 'Team',
    },
  });
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
    context: {
      plan: 'Team',
    },
  });

  assert.equal(calls.length, 3);
  assert.equal(calls[0].url, 'http://127.0.0.1:8000/api/widget/bootstrap');
  assert.deepEqual(JSON.parse(calls[0].options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    page_url: 'https://docs.example.test/install',
    context: {
      plan: 'Team',
    },
  });
  assert.equal(calls[1].url, 'http://127.0.0.1:8000/api/conversations');
  assert.deepEqual(JSON.parse(calls[1].options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    visitor_token: 'visitor-token-123',
    subject: 'Can you help me?',
    page_url: 'https://docs.example.test/install',
    context: {
      plan: 'Team',
    },
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

test('can request a visitor read receipt when fetching messages', async () => {
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
          messages: [],
        },
      });
    },
  });

  await client.fetchMessages('WF-TEST123', { markSeen: true });

  assert.equal(calls.length, 1);
  assert.equal(
    calls[0].url,
    'http://127.0.0.1:8000/api/conversations/WF-TEST123/messages?site_public_key=site_public_docs&anonymous_id=anon-browser-123&visitor_token=visitor-token-123&mark_seen=1',
  );
  assert.equal(calls[0].options.method, 'GET');
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

test('fetches cobrowse status through the public visitor API', async () => {
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
            status: 'requested',
            consent: 'requested',
            requested_by: {
              name: 'Ada Agent',
            },
          },
        },
      });
    },
  });

  const result = await client.fetchCobrowseStatus('WF-TEST123');

  assert.equal(calls.length, 1);
  assert.equal(
    calls[0].url,
    'http://127.0.0.1:8000/api/conversations/WF-TEST123/cobrowse?site_public_key=site_public_docs&anonymous_id=anon-browser-123&visitor_token=visitor-token-123',
  );
  assert.equal(calls[0].options.method, 'GET');
  assert.equal(result.conversation.support_code, 'WF-TEST123');
  assert.equal(result.cobrowse.status, 'requested');
  assert.equal(result.cobrowse.requested_by.name, 'Ada Agent');
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

test('reports cobrowse page state through the public visitor API', async () => {
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
          page_state: {
            page_url: 'https://docs.example.test/install?step=2',
            title: 'Install Guide',
            viewport_width: 1366,
            viewport_height: 768,
            scroll_x: 0,
            scroll_y: 420,
            visibility_state: 'visible',
            focused: true,
          },
        },
      });
    },
  });

  const result = await client.reportCobrowsePageState('WF-TEST123', {
    pageUrl: 'https://docs.example.test/install?step=2',
    title: 'Install Guide',
    viewportWidth: 1366,
    viewportHeight: 768,
    scrollX: 0,
    scrollY: 420,
    visibilityState: 'visible',
    focused: true,
  });

  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, 'http://127.0.0.1:8000/api/conversations/WF-TEST123/cobrowse-page-state');
  assert.deepEqual(JSON.parse(calls[0].options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    visitor_token: 'visitor-token-123',
    page_url: 'https://docs.example.test/install?step=2',
    title: 'Install Guide',
    viewport_width: 1366,
    viewport_height: 768,
    scroll_x: 0,
    scroll_y: 420,
    visibility_state: 'visible',
    focused: true,
  });
  assert.equal(result.page_state.page_url, 'https://docs.example.test/install?step=2');
});

test('reports cobrowse snapshots through the public visitor API', async () => {
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
          snapshot: {
            page_url: 'https://docs.example.test/install?step=2',
            title: 'Install Guide',
            html_length: 53,
            text_length: 27,
            node_count: 4,
            masked_count: 1,
          },
        },
      });
    },
  });

  const result = await client.reportCobrowseSnapshot('WF-TEST123', {
    pageUrl: 'https://docs.example.test/install?step=2',
    title: 'Install Guide',
    html: '<main><p>Hello visitor.</p><input value="[masked]"></main>',
    text: 'Hello visitor. [masked]',
    nodeCount: 4,
    maskedCount: 1,
  });

  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, 'http://127.0.0.1:8000/api/conversations/WF-TEST123/cobrowse-snapshot');
  assert.deepEqual(JSON.parse(calls[0].options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    visitor_token: 'visitor-token-123',
    page_url: 'https://docs.example.test/install?step=2',
    title: 'Install Guide',
    html: '<main><p>Hello visitor.</p><input value="[masked]"></main>',
    text: 'Hello visitor. [masked]',
    node_count: 4,
    masked_count: 1,
  });
  assert.equal(result.snapshot.masked_count, 1);
});

test('reports cobrowse mutation batches through the public visitor API', async () => {
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
          mutations: {
            last_sequence: 3,
            batch_count: 3,
            mutation_count: 7,
            dropped_count: 1,
            skipped_count: 2,
            recent_batches_count: 3,
          },
        },
      });
    },
  });

  const result = await client.reportCobrowseMutations('WF-TEST123', {
    pageUrl: 'https://docs.example.test/install?step=2',
    sequence: 3,
    droppedCount: 1,
    skippedCount: 2,
    mutations: [
      {
        type: 'text',
        path: 'body > main > p:nth-child(2)',
        text: 'Updated public copy.',
      },
    ],
  });

  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, 'http://127.0.0.1:8000/api/conversations/WF-TEST123/cobrowse-mutations');
  assert.deepEqual(JSON.parse(calls[0].options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    visitor_token: 'visitor-token-123',
    page_url: 'https://docs.example.test/install?step=2',
    sequence: 3,
    dropped_count: 1,
    skipped_count: 2,
    mutations: [
      {
        type: 'text',
        path: 'body > main > p:nth-child(2)',
        text: 'Updated public copy.',
      },
    ],
  });
  assert.equal(result.mutations.batch_count, 3);
});

test('creates a masked cobrowse snapshot from the document', () => {
  const dom = new JSDOM([
    '<!doctype html><html><head><title>Checkout</title></head><body>',
    '<main>',
    '  <h1>Checkout</h1>',
    '  <p>Public checkout content.</p>',
    '  <input type="password" value="secret-password">',
    '  <div data-wayfindr-mask>Card number 4111 1111 1111 1111</div>',
    '  <div data-secret>Internal token abc123</div>',
    '  <script>window.stolen = "nope";</script>',
    '</main>',
    '<div class="wayfindr-widget">Widget chrome should not be mirrored.</div>',
    '</body></html>',
  ].join(''), {
    url: 'https://docs.example.test/checkout',
  });

  const snapshot = Wayfindr.createCobrowseSnapshot(dom.window.document, {
    location: dom.window.location,
    maskSelectors: ['[data-secret]'],
  });

  assert.equal(snapshot.pageUrl, 'https://docs.example.test/checkout');
  assert.equal(snapshot.title, 'Checkout');
  assert.match(snapshot.html, /Public checkout content/);
  assert.match(snapshot.text, /Public checkout content/);
  assert.match(snapshot.html, /\[masked\]/);
  assert.match(snapshot.text, /\[masked\]/);
  assert.equal(snapshot.html.includes('secret-password'), false);
  assert.equal(snapshot.html.includes('4111 1111 1111 1111'), false);
  assert.equal(snapshot.html.includes('Internal token abc123'), false);
  assert.equal(snapshot.html.includes('window.stolen'), false);
  assert.equal(snapshot.html.includes('Widget chrome'), false);
  assert.equal(snapshot.maskedCount, 3);
  assert(snapshot.nodeCount > 0);
});

test('infers and masks sensitive cobrowse snapshot fields before export', () => {
  const dom = new JSDOM([
    '<!doctype html><html><head><title>Account</title></head><body>',
    '<main>',
    '  <h1>Account settings</h1>',
    '  <p>Public account help stays visible.</p>',
    '  <div id="billing-card-number">4111 1111 1111 1111</div>',
    '  <span data-field="username">adam@example.com</span>',
    '  <div aria-label="API token">sk_live_secret_token</div>',
    '</main>',
    '</body></html>',
  ].join(''), {
    url: 'https://docs.example.test/account',
  });

  const snapshot = Wayfindr.createCobrowseSnapshot(dom.window.document, {
    location: dom.window.location,
  });

  assert.match(snapshot.html, /Public account help stays visible/);
  assert.match(snapshot.html, /\[masked\]/);
  assert.equal(snapshot.html.includes('4111 1111 1111 1111'), false);
  assert.equal(snapshot.html.includes('adam@example.com'), false);
  assert.equal(snapshot.html.includes('sk_live_secret_token'), false);
  assert.equal(snapshot.text.includes('4111 1111 1111 1111'), false);
  assert.equal(snapshot.text.includes('adam@example.com'), false);
  assert.equal(snapshot.text.includes('sk_live_secret_token'), false);
});

test('allows hosts to mark inferred cobrowse false positives as shareable', () => {
  const dom = new JSDOM([
    '<!doctype html><html><head><title>Support</title></head><body>',
    '<main>',
    '  <p data-wayfindr-allow id="public-email-policy">Email support@example.com for help.</p>',
    '</main>',
    '</body></html>',
  ].join(''), {
    url: 'https://docs.example.test/support',
  });

  const snapshot = Wayfindr.createCobrowseSnapshot(dom.window.document, {
    location: dom.window.location,
  });

  assert.match(snapshot.html, /Email support@example.com for help/);
  assert.equal(snapshot.maskedCount, 0);
});

test('creates sanitized cobrowse mutation batches from mutation records', () => {
  const dom = new JSDOM([
    '<!doctype html><html><head><title>Checkout</title></head><body>',
    '<main>',
    '  <h1>Checkout</h1>',
    '  <p id="public-copy">Public checkout content.</p>',
    '  <p id="secret-copy" data-wayfindr-mask>Card number 4111 1111 1111 1111</p>',
    '  <button id="toggle" aria-expanded="false">Details</button>',
    '</main>',
    '<div class="wayfindr-widget">Widget chrome should not be mirrored.</div>',
    '</body></html>',
  ].join(''), {
    url: 'https://docs.example.test/checkout',
  });
  const doc = dom.window.document;
  const publicCopy = doc.querySelector('#public-copy');
  const secretCopy = doc.querySelector('#secret-copy');
  const toggle = doc.querySelector('#toggle');
  const added = doc.createElement('p');

  publicCopy.textContent = 'Updated public checkout content.';
  secretCopy.textContent = 'Card number 4242 4242 4242 4242';
  toggle.setAttribute('aria-expanded', 'true');
  added.textContent = 'Fresh public hint.';

  const batch = Wayfindr.createCobrowseMutationBatch([
    {
      type: 'characterData',
      target: publicCopy.firstChild,
    },
    {
      type: 'characterData',
      target: secretCopy.firstChild,
    },
    {
      type: 'attributes',
      target: toggle,
      attributeName: 'aria-expanded',
    },
    {
      type: 'childList',
      target: doc.querySelector('main'),
      addedNodes: [added],
      removedNodes: [],
    },
  ], {
    document: doc,
    location: dom.window.location,
    sequence: 9,
  });

  assert.equal(batch.pageUrl, 'https://docs.example.test/checkout');
  assert.equal(batch.sequence, 9);
  assert.equal(batch.mutations.length, 4);
  assert.equal(batch.skippedCount, 0);
  assert.equal(batch.mutations[0].type, 'text');
  assert.equal(batch.mutations[0].text, 'Updated public checkout content.');
  assert.equal(batch.mutations[1].text, '[masked]');
  assert.equal(batch.mutations[2].type, 'attribute');
  assert.equal(batch.mutations[2].attributeName, 'aria-expanded');
  assert.equal(batch.mutations[2].attributeValue, 'true');
  assert.equal(batch.mutations[3].type, 'added');
  assert.match(batch.mutations[3].html, /Fresh public hint/);
  assert.equal(JSON.stringify(batch).includes('4242 4242 4242 4242'), false);
  assert.equal(JSON.stringify(batch).includes('Widget chrome'), false);
});

test('skips cobrowse mutation records that would exceed the client payload budget', () => {
  const dom = new JSDOM([
    '<!doctype html><html><head><title>Checkout</title></head><body>',
    '<main>',
    '  <p id="small-copy">Small public update.</p>',
    '  <p id="large-copy-one">Initial one.</p>',
    '  <p id="large-copy-two">Initial two.</p>',
    '</main>',
    '</body></html>',
  ].join(''), {
    url: 'https://docs.example.test/checkout',
  });
  const doc = dom.window.document;
  const smallCopy = doc.querySelector('#small-copy');
  const largeCopyOne = doc.querySelector('#large-copy-one');
  const largeCopyTwo = doc.querySelector('#large-copy-two');

  smallCopy.textContent = 'Small public update.';
  largeCopyOne.textContent = 'First huge update '.repeat(120);
  largeCopyTwo.textContent = 'Second huge update '.repeat(120);

  const batch = Wayfindr.createCobrowseMutationBatch([
    {
      type: 'characterData',
      target: smallCopy.firstChild,
    },
    {
      type: 'characterData',
      target: largeCopyOne.firstChild,
    },
    {
      type: 'characterData',
      target: largeCopyTwo.firstChild,
    },
  ], {
    document: doc,
    location: dom.window.location,
    maxPayloadBytes: 500,
  });

  assert.equal(batch.mutations.length, 1);
  assert.equal(batch.mutations[0].text, 'Small public update.');
  assert.equal(batch.skippedCount, 2);
  assert(Buffer.byteLength(JSON.stringify(batch), 'utf8') <= 500);
  assert.equal(JSON.stringify(batch).includes('First huge update'), false);
  assert.equal(JSON.stringify(batch).includes('Second huge update'), false);
});

test('infers and masks sensitive cobrowse mutation content before export', () => {
  const dom = new JSDOM([
    '<!doctype html><html><head><title>Account</title></head><body>',
    '<main>',
    '  <span data-field="username">old@example.com</span>',
    '</main>',
    '</body></html>',
  ].join(''), {
    url: 'https://docs.example.test/account',
  });
  const doc = dom.window.document;
  const username = doc.querySelector('[data-field="username"]');
  const added = doc.createElement('div');

  username.textContent = 'adam@example.com';
  added.setAttribute('id', 'billing-card-number');
  added.textContent = '4111 1111 1111 1111';

  const batch = Wayfindr.createCobrowseMutationBatch([
    {
      type: 'characterData',
      target: username.firstChild,
    },
    {
      type: 'childList',
      target: doc.querySelector('main'),
      addedNodes: [added],
      removedNodes: [],
    },
  ], {
    document: doc,
    location: dom.window.location,
  });

  assert.equal(batch.mutations.length, 2);
  assert.equal(batch.mutations[0].text, '[masked]');
  assert.match(batch.mutations[1].html, /\[masked\]/);
  assert.equal(JSON.stringify(batch).includes('adam@example.com'), false);
  assert.equal(JSON.stringify(batch).includes('4111 1111 1111 1111'), false);
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
  const connectionHandlers = {};
  const unboundConnectionHandlers = {};
  let unsubscribed = false;
  let disconnected = false;

  function FakePusher(key, options) {
    appKey = key;
    pusherOptions = options;
    this.connection = {
      bind: (eventName, handler) => {
        connectionHandlers[eventName] = handler;
      },
      unbind: (eventName, handler) => {
        unboundConnectionHandlers[eventName] = handler;
      },
    };

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

  const connectionStates = [];
  const subscription = client.subscribeToConversation('WF-TEST123', () => {}, (state) => {
    connectionStates.push(state);
  });
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
  assert.equal(typeof connectionHandlers.state_change, 'function');
  assert.equal(typeof connectionHandlers.error, 'function');

  connectionHandlers.state_change({ current: 'connected' });
  connectionHandlers.error();

  assert.deepEqual(connectionStates, ['connected', 'unavailable']);

  subscription.unsubscribe();

  assert.equal(unbound, true);
  assert.equal(unboundConnectionHandlers.state_change, connectionHandlers.state_change);
  assert.equal(unboundConnectionHandlers.error, connectionHandlers.error);
  assert.equal(unsubscribed, true);
  assert.equal(disconnected, true);
});

test('binds Laravel custom broadcast names for built-in Pusher subscriptions', () => {
  const boundEvents = [];
  const unboundEvents = [];

  function FakePusher() {
    this.subscribe = () => ({
      bind: (eventName) => {
        boundEvents.push(eventName);
      },
      unbind: (eventName) => {
        unboundEvents.push(eventName);
      },
    });
    this.unsubscribe = () => {};
    this.disconnect = () => {};
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
    fetch: async () => jsonResponse(200, {
      auth: 'reverb-key:signed-channel',
    }),
  });

  const subscription = client.subscribeToConversation('WF-TEST123', () => {});

  assert.deepEqual(boundEvents, [
    'conversation.message.created',
    '.conversation.message.created',
  ]);

  subscription.unsubscribe();

  assert.deepEqual(unboundEvents, [
    'conversation.message.created',
    '.conversation.message.created',
  ]);
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
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
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

      if (url.includes('/api/conversations/WF-TEST123/cobrowse?')) {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: {
              status: 'unavailable',
              consent: 'unavailable',
              requested_by: null,
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
    messageSummaries(widget),
    ['VisitorCan you help me?'],
  );
  assert.equal(
    widget.root.querySelector('.wayfindr-widget__message-time').dateTime,
    '2026-05-23T14:00:00.000000Z',
  );

  const refresh = widget.root.querySelector('.wayfindr-widget__refresh');

  assert.equal(refresh.hidden, false);

  refresh.dispatchEvent(new dom.window.Event('click', { bubbles: true }));

  await settle();

  assert.deepEqual(
    messageSummaries(widget),
    ['VisitorCan you help me?', 'Ada AgentAbsolutely, happy to help.'],
  );
  assert.deepEqual(
    [...widget.root.querySelectorAll('.wayfindr-widget__message-time')].map((time) => time.dateTime),
    ['2026-05-23T14:00:00.000000Z', '2026-05-23T14:01:00.000000Z'],
  );
});

test('marks consecutive widget messages from the same sender as a visual group', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    storage: memoryStorage(),
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: async (url) => {
      const path = new URL(url).pathname;

      if (path === '/api/widget/bootstrap') {
        return jsonResponse(201, {
          data: {
            site: { public_key: 'site_public_docs', settings: {} },
            visitor: { anonymous_id: 'anon-browser-123', token: 'visitor-token-123' },
          },
        });
      }

      if (path === '/api/conversations') {
        return jsonResponse(201, {
          data: {
            support_code: 'WF-TEST123',
            status: 'open',
          },
        });
      }

      if (path === '/api/conversations/WF-TEST123/cobrowse') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: {
              status: 'unavailable',
              consent: 'unavailable',
              requested_by: null,
            },
          },
        });
      }

      if (path === '/api/conversations/WF-TEST123/messages') {
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
                body: 'First thought.',
                created_at: '2026-05-23T14:00:00.000000Z',
              },
              {
                id: 2,
                sender: { kind: 'visitor', name: 'Visitor' },
                type: 'text',
                body: 'One more detail.',
                created_at: '2026-05-23T14:02:00.000000Z',
              },
              {
                id: 3,
                sender: { kind: 'agent', name: 'Ada Agent' },
                type: 'text',
                body: 'I am on it.',
                created_at: '2026-05-23T14:03:00.000000Z',
              },
              {
                id: 4,
                sender: { kind: 'agent', name: 'Ada Agent' },
                type: 'text',
                body: 'Following up later.',
                created_at: '2026-05-23T14:10:00.000000Z',
              },
            ],
          },
        });
      }

      throw new Error('Unexpected request ' + url);
    },
  });

  widget.open();

  widget.root.querySelector('.wayfindr-widget__textarea').value = 'First thought.';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );

  await settle();

  const messages = [...widget.root.querySelectorAll('.wayfindr-widget__message')];

  assert.equal(messages[0].classList.contains('wayfindr-widget__message--grouped'), false);
  assert.equal(messages[1].classList.contains('wayfindr-widget__message--grouped'), true);
  assert.equal(messages[2].classList.contains('wayfindr-widget__message--grouped'), false);
  assert.equal(messages[3].classList.contains('wayfindr-widget__message--grouped'), false);
  assert.equal(messages[1].querySelector('.wayfindr-widget__message-name').textContent, 'Visitor');
  assert.equal(messages[1].querySelector('.wayfindr-widget__message-time').dateTime, '2026-05-23T14:02:00.000000Z');
});

test('shows sent delivery status only for visitor messages', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    storage: memoryStorage(),
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: async (url) => {
      const path = new URL(url).pathname;

      if (path === '/api/widget/bootstrap') {
        return jsonResponse(201, {
          data: {
            site: { public_key: 'site_public_docs', settings: {} },
            visitor: { anonymous_id: 'anon-browser-123', token: 'visitor-token-123' },
          },
        });
      }

      if (path === '/api/conversations') {
        return jsonResponse(201, {
          data: {
            support_code: 'WF-TEST123',
            status: 'open',
          },
        });
      }

      if (path === '/api/conversations/WF-TEST123/cobrowse') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: {
              status: 'unavailable',
              consent: 'unavailable',
              requested_by: null,
            },
          },
        });
      }

      if (path === '/api/conversations/WF-TEST123/messages') {
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
              {
                id: 2,
                sender: { kind: 'agent', name: 'Ada Agent' },
                type: 'text',
                body: 'Absolutely.',
                created_at: '2026-05-23T14:01:00.000000Z',
              },
            ],
          },
        });
      }

      throw new Error('Unexpected request ' + url);
    },
  });

  widget.open();

  widget.root.querySelector('.wayfindr-widget__textarea').value = 'Can you help me?';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );

  await settle();

  const messages = [...widget.root.querySelectorAll('.wayfindr-widget__message')];
  const visitorDelivery = messages[0].querySelector('.wayfindr-widget__message-delivery');

  assert.equal(visitorDelivery.textContent, 'Sent');
  assert.equal(visitorDelivery.getAttribute('aria-label'), 'Message delivery status');
  assert.equal(messages[1].querySelector('.wayfindr-widget__message-delivery'), null);
});

test('keeps the widget composer busy and ignores duplicate submits while sending', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  const calls = [];
  const sendResponse = deferred();

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    storage: memoryStorage(),
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: async (url, options) => {
      const path = new URL(url).pathname;
      calls.push({ url, options });

      if (path === '/api/widget/bootstrap') {
        return jsonResponse(201, {
          data: {
            site: { public_key: 'site_public_docs', settings: {} },
            visitor: { anonymous_id: 'anon-browser-123', token: 'visitor-token-123' },
          },
        });
      }

      if (path === '/api/conversations') {
        return jsonResponse(201, {
          data: {
            support_code: 'WF-TEST123',
            status: 'open',
          },
        });
      }

      if (path === '/api/conversations/WF-TEST123/messages' && options.method === 'POST') {
        return sendResponse.promise;
      }

      if (path === '/api/conversations/WF-TEST123/messages') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123', status: 'open' },
            messages: [{
              id: 1,
              sender: { kind: 'visitor', name: 'Visitor' },
              type: 'text',
              body: 'Can you help me?',
              created_at: '2026-05-23T14:00:00.000000Z',
            }],
          },
        });
      }

      if (path === '/api/conversations/WF-TEST123/cobrowse') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: {
              status: 'unavailable',
              consent: 'unavailable',
              requested_by: null,
            },
          },
        });
      }

      throw new Error('Unexpected request ' + url);
    },
  });

  widget.open();

  const form = widget.root.querySelector('.wayfindr-widget__form');
  const textarea = widget.root.querySelector('.wayfindr-widget__textarea');
  const send = widget.root.querySelector('.wayfindr-widget__send');

  textarea.value = 'Can you help me?';
  form.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));

  await settle();

  assert.equal(form.getAttribute('aria-busy'), 'true');
  assert.equal(textarea.disabled, true);
  assert.equal(send.disabled, true);
  assert.equal(send.textContent, 'Sending...');

  textarea.value = 'Duplicate click.';
  form.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));

  await settle();

  assert.equal(calls.filter((call) => new URL(call.url).pathname === '/api/conversations').length, 1);
  assert.equal(
    calls.filter((call) => new URL(call.url).pathname === '/api/conversations/WF-TEST123/messages' && call.options.method === 'POST').length,
    1,
  );

  sendResponse.resolve(jsonResponse(201, {
    data: {
      conversation: { support_code: 'WF-TEST123' },
      message: {
        sender: { kind: 'visitor', name: 'Visitor' },
        type: 'text',
        body: 'Can you help me?',
        created_at: '2026-05-23T14:00:00.000000Z',
      },
    },
  }));

  await settle();

  assert.equal(form.getAttribute('aria-busy'), 'false');
  assert.equal(textarea.disabled, false);
  assert.equal(send.disabled, false);
  assert.equal(send.textContent, 'Send message');
});

test('preserves failed first message drafts and retries without recreating the conversation', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  const calls = [];
  let messageAttempts = 0;

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    storage: memoryStorage(),
    cobrowseStatusPollMs: 10,
    messagePollMs: 10,
    fetch: async (url, options) => {
      const path = new URL(url).pathname;
      calls.push({ url, options });

      if (path === '/api/widget/bootstrap') {
        return jsonResponse(201, {
          data: {
            site: { public_key: 'site_public_docs', settings: {} },
            visitor: { anonymous_id: 'anon-browser-123', token: 'visitor-token-123' },
          },
        });
      }

      if (path === '/api/conversations') {
        return jsonResponse(201, {
          data: {
            support_code: 'WF-RETRY123',
            status: 'open',
          },
        });
      }

      if (path === '/api/conversations/WF-RETRY123/messages' && options.method === 'POST') {
        messageAttempts += 1;

        if (messageAttempts === 1) {
          return jsonResponse(500, {
            message: 'Database unavailable.',
          });
        }

        return jsonResponse(201, {
          data: {
            conversation: { support_code: 'WF-RETRY123' },
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

      if (path === '/api/conversations/WF-RETRY123/messages') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-RETRY123', status: 'open' },
            messages: [{
              id: 1,
              sender: { kind: 'visitor', name: 'Visitor' },
              type: 'text',
              body: 'Can you help me?',
              created_at: '2026-05-23T14:00:00.000000Z',
            }],
          },
        });
      }

      if (path === '/api/conversations/WF-RETRY123/cobrowse') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-RETRY123' },
            cobrowse: {
              status: 'unavailable',
              consent: 'unavailable',
              requested_by: null,
            },
          },
        });
      }

      throw new Error('Unexpected request ' + url);
    },
  });

  widget.open();

  const form = widget.root.querySelector('.wayfindr-widget__form');
  const textarea = widget.root.querySelector('.wayfindr-widget__textarea');
  const status = widget.root.querySelector('.wayfindr-widget__status');
  const notice = widget.root.querySelector('.wayfindr-widget__notice');

  textarea.value = 'Can you help me?';
  form.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));

  await settle();
  await wait(35);

  assert.equal(textarea.value, 'Can you help me?');
  assert.match(status.textContent, /Message could not be sent/);
  assert.doesNotMatch(status.textContent, /Database unavailable/);
  assert.equal(notice.hidden, false);
  assert.equal(notice.getAttribute('data-state'), 'warning');
  assert.match(notice.textContent, /Message could not be sent/);
  assert.equal(calls.filter((call) => new URL(call.url).pathname === '/api/conversations').length, 1);
  assert.equal(
    calls.filter((call) => new URL(call.url).pathname === '/api/conversations/WF-RETRY123/messages' && call.options.method === 'GET').length,
    0,
  );
  assert.equal(
    calls.filter((call) => new URL(call.url).pathname === '/api/conversations/WF-RETRY123/cobrowse' && call.options.method === 'GET').length,
    0,
  );
  assert.equal(widget.root.querySelector('.wayfindr-widget__refresh').hidden, true);
  assert.equal(widget.root.querySelector('.wayfindr-widget__connection').hidden, true);

  form.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));

  await settle();

  assert.equal(textarea.value, '');
  assert.match(status.textContent, /Support code WF-RETRY123/);
  assert.equal(calls.filter((call) => new URL(call.url).pathname === '/api/conversations').length, 1);
  assert.equal(
    calls.filter((call) => new URL(call.url).pathname === '/api/conversations/WF-RETRY123/messages' && call.options.method === 'POST').length,
    2,
  );
  assert.deepEqual(
    messageSummaries(widget),
    ['VisitorCan you help me?'],
  );

  widget.destroy();
});

test('shows a calm busy state while refreshing widget messages', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  const refreshResponse = deferred();
  let timelineFetches = 0;

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    storage: memoryStorage(),
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: async (url, options) => {
      const path = new URL(url).pathname;

      if (path === '/api/widget/bootstrap') {
        return jsonResponse(201, {
          data: {
            site: { public_key: 'site_public_docs', settings: {} },
            visitor: { anonymous_id: 'anon-browser-123', token: 'visitor-token-123' },
          },
        });
      }

      if (path === '/api/conversations') {
        return jsonResponse(201, {
          data: {
            support_code: 'WF-TEST123',
            status: 'open',
          },
        });
      }

      if (path === '/api/conversations/WF-TEST123/messages' && options.method === 'POST') {
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

      if (path === '/api/conversations/WF-TEST123/messages') {
        timelineFetches += 1;

        if (timelineFetches > 1) {
          return refreshResponse.promise;
        }

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123', status: 'open' },
            messages: [{
              id: 1,
              sender: { kind: 'visitor', name: 'Visitor' },
              type: 'text',
              body: 'Can you help me?',
              created_at: '2026-05-23T14:00:00.000000Z',
            }],
          },
        });
      }

      if (path === '/api/conversations/WF-TEST123/cobrowse') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: {
              status: 'unavailable',
              consent: 'unavailable',
              requested_by: null,
            },
          },
        });
      }

      throw new Error('Unexpected request ' + url);
    },
  });

  widget.open();

  widget.root.querySelector('.wayfindr-widget__textarea').value = 'Can you help me?';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );

  await settle();

  const refresh = widget.root.querySelector('.wayfindr-widget__refresh');

  refresh.dispatchEvent(new dom.window.Event('click', { bubbles: true }));

  await settle();

  assert.equal(refresh.disabled, true);
  assert.equal(refresh.getAttribute('aria-busy'), 'true');
  assert.equal(refresh.textContent, 'Refreshing...');

  refreshResponse.resolve(jsonResponse(200, {
    data: {
      conversation: { support_code: 'WF-TEST123', status: 'open' },
      messages: [{
        id: 1,
        sender: { kind: 'visitor', name: 'Visitor' },
        type: 'text',
        body: 'Can you help me?',
        created_at: '2026-05-23T14:00:00.000000Z',
      }, {
        id: 2,
        sender: { kind: 'agent', name: 'Ada Agent' },
        type: 'text',
        body: 'Still here with you.',
        created_at: '2026-05-23T14:01:00.000000Z',
      }],
    },
  }));

  await settle();

  assert.equal(refresh.disabled, false);
  assert.equal(refresh.getAttribute('aria-busy'), 'false');
  assert.equal(refresh.textContent, 'Refresh');
  assert.deepEqual(
    messageSummaries(widget),
    ['VisitorCan you help me?', 'Ada AgentStill here with you.'],
  );
});

test('keeps the current widget timeline visible when manual refresh fails', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  let timelineFetches = 0;

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    storage: memoryStorage(),
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: async (url, options) => {
      const path = new URL(url).pathname;

      if (path === '/api/widget/bootstrap') {
        return jsonResponse(201, {
          data: {
            site: { public_key: 'site_public_docs', settings: {} },
            visitor: { anonymous_id: 'anon-browser-123', token: 'visitor-token-123' },
          },
        });
      }

      if (path === '/api/conversations') {
        return jsonResponse(201, {
          data: {
            support_code: 'WF-TEST123',
            status: 'open',
          },
        });
      }

      if (path === '/api/conversations/WF-TEST123/messages' && options.method === 'POST') {
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

      if (path === '/api/conversations/WF-TEST123/messages') {
        timelineFetches += 1;

        if (timelineFetches > 1) {
          return jsonResponse(503, {
            message: 'Upstream timeout.',
          });
        }

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123', status: 'open' },
            messages: [{
              id: 1,
              sender: { kind: 'visitor', name: 'Visitor' },
              type: 'text',
              body: 'Can you help me?',
              created_at: '2026-05-23T14:00:00.000000Z',
            }],
          },
        });
      }

      if (path === '/api/conversations/WF-TEST123/cobrowse') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: {
              status: 'unavailable',
              consent: 'unavailable',
              requested_by: null,
            },
          },
        });
      }

      throw new Error('Unexpected request ' + url);
    },
  });

  widget.open();

  widget.root.querySelector('.wayfindr-widget__textarea').value = 'Can you help me?';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );

  await settle();

  const refresh = widget.root.querySelector('.wayfindr-widget__refresh');
  const status = widget.root.querySelector('.wayfindr-widget__status');

  refresh.dispatchEvent(new dom.window.Event('click', { bubbles: true }));

  await settle();

  assert.deepEqual(
    messageSummaries(widget),
    ['VisitorCan you help me?'],
  );
  assert.equal(refresh.disabled, false);
  assert.equal(refresh.getAttribute('aria-busy'), 'false');
  assert.match(status.textContent, /Messages could not be refreshed/);
  assert.doesNotMatch(status.textContent, /Upstream timeout/);
});

test('shows a retry notice after failed refresh and clears it after retry succeeds', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  let timelineFetches = 0;
  const retryResponse = deferred();

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    storage: memoryStorage(),
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: async (url, options) => {
      const path = new URL(url).pathname;

      if (path === '/api/widget/bootstrap') {
        return jsonResponse(201, {
          data: {
            site: { public_key: 'site_public_docs', settings: {} },
            visitor: { anonymous_id: 'anon-browser-123', token: 'visitor-token-123' },
          },
        });
      }

      if (path === '/api/conversations') {
        return jsonResponse(201, {
          data: {
            support_code: 'WF-RETRYREFRESH',
            status: 'open',
          },
        });
      }

      if (path === '/api/conversations/WF-RETRYREFRESH/messages' && options.method === 'POST') {
        return jsonResponse(201, {
          data: {
            conversation: { support_code: 'WF-RETRYREFRESH' },
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

      if (path === '/api/conversations/WF-RETRYREFRESH/messages') {
        timelineFetches += 1;

        if (timelineFetches === 2) {
          return jsonResponse(503, {
            message: 'Upstream timeout.',
          });
        }

        if (timelineFetches > 2) {
          return retryResponse.promise;
        }

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-RETRYREFRESH', status: 'open' },
            messages: [{
              id: 1,
              sender: { kind: 'visitor', name: 'Visitor' },
              type: 'text',
              body: 'Can you help me?',
              created_at: '2026-05-23T14:00:00.000000Z',
            }, ...(timelineFetches > 2
              ? [{
                  id: 2,
                  sender: { kind: 'agent', name: 'Ada Agent' },
                  type: 'text',
                  body: 'Still here with you.',
                  created_at: '2026-05-23T14:01:00.000000Z',
                }]
              : [])],
          },
        });
      }

      if (path === '/api/conversations/WF-RETRYREFRESH/cobrowse') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-RETRYREFRESH' },
            cobrowse: {
              status: 'unavailable',
              consent: 'unavailable',
              requested_by: null,
            },
          },
        });
      }

      throw new Error('Unexpected request ' + url);
    },
  });

  widget.open();

  widget.root.querySelector('.wayfindr-widget__textarea').value = 'Can you help me?';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );

  await settle();

  const notice = widget.root.querySelector('.wayfindr-widget__notice');
  const retry = widget.root.querySelector('.wayfindr-widget__notice-retry');
  const refresh = widget.root.querySelector('.wayfindr-widget__refresh');

  refresh.dispatchEvent(new dom.window.Event('click', { bubbles: true }));

  await settle();

  assert.deepEqual(messageSummaries(widget), ['VisitorCan you help me?']);
  assert.equal(notice.hidden, false);
  assert.equal(notice.getAttribute('data-state'), 'warning');
  assert.match(notice.textContent, /Messages could not be refreshed/);
  assert.equal(retry.hidden, false);

  retry.dispatchEvent(new dom.window.Event('click', { bubbles: true }));

  assert.equal(retry.disabled, true);

  retryResponse.resolve(jsonResponse(200, {
    data: {
      conversation: { support_code: 'WF-RETRYREFRESH', status: 'open' },
      messages: [{
        id: 1,
        sender: { kind: 'visitor', name: 'Visitor' },
        type: 'text',
        body: 'Can you help me?',
        created_at: '2026-05-23T14:00:00.000000Z',
      }, {
        id: 2,
        sender: { kind: 'agent', name: 'Ada Agent' },
        type: 'text',
        body: 'Still here with you.',
        created_at: '2026-05-23T14:01:00.000000Z',
      }],
    },
  }));

  await settle();

  assert.deepEqual(messageSummaries(widget), ['VisitorCan you help me?', 'Ada AgentStill here with you.']);
  assert.equal(notice.hidden, true);
  assert.equal(retry.hidden, true);
});

test('polls active conversations so agent replies appear when realtime is unavailable', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  let timelineFetches = 0;

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    storage: memoryStorage(),
    cobrowseStatusPollMs: 0,
    messagePollMs: 10,
    fetch: async (url, options) => {
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

      if (url.includes('/api/conversations/WF-TEST123/cobrowse?')) {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: {
              status: 'unavailable',
              consent: 'unavailable',
              requested_by: null,
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
                  body: 'Fallback hello.',
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
  await wait(30);
  await settle();

  assert.deepEqual(
    messageSummaries(widget),
    ['VisitorCan you help me?', 'Ada AgentFallback hello.'],
  );
  assert.match(
    widget.root.querySelector('.wayfindr-widget__connection').textContent,
    /Using periodic refresh for updates/,
  );

  widget.destroy();
});

test('does not mark closed-panel background polls as visitor reads', async () => {
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
    cobrowseStatusPollMs: 0,
    messagePollMs: 50,
    fetch: async (url, options) => {
      const parsed = new URL(url);
      const path = parsed.pathname;
      calls.push({ url, options });

      if (path === '/api/widget/bootstrap') {
        return jsonResponse(201, {
          data: {
            site: { public_key: 'site_public_docs', settings: {} },
            visitor: { anonymous_id: 'anon-browser-123', token: 'visitor-token-123' },
          },
        });
      }

      if (path === '/api/conversations') {
        return jsonResponse(201, {
          data: {
            support_code: 'WF-TEST123',
            status: 'open',
          },
        });
      }

      if (path === '/api/conversations/WF-TEST123/messages' && options.method === 'POST') {
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

      if (path === '/api/conversations/WF-TEST123/messages') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123', status: 'open' },
            messages: [{
              id: 1,
              sender: { kind: 'visitor', name: 'Visitor' },
              type: 'text',
              body: 'Can you help me?',
              created_at: '2026-05-23T14:00:00.000000Z',
            }, {
              id: 2,
              sender: { kind: 'agent', name: 'Ada Agent' },
              type: 'text',
              body: 'Fallback hello.',
              created_at: '2026-05-23T14:01:00.000000Z',
            }],
          },
        });
      }

      if (path === '/api/conversations/WF-TEST123/cobrowse') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: {
              status: 'unavailable',
              consent: 'unavailable',
              requested_by: null,
            },
          },
        });
      }

      throw new Error('Unexpected request ' + url);
    },
  });

  widget.open();

  widget.root.querySelector('.wayfindr-widget__textarea').value = 'Can you help me?';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );

  await settle();
  widget.close();
  await wait(70);
  await settle();

  const messageReads = calls
    .filter((call) => new URL(call.url).pathname === '/api/conversations/WF-TEST123/messages' && call.options.method === 'GET')
    .map((call) => new URL(call.url).searchParams.get('mark_seen'));

  assert.equal(messageReads.length >= 2, true);
  assert.equal(messageReads[0], '1');
  assert.equal(messageReads[messageReads.length - 1], null);

  widget.destroy();
});

test('appends live agent messages from the realtime subscription', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  let liveMessage = null;
  let setRealtimeState = null;
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
      subscribe: ({ onMessage, onConnectionState }) => {
        liveMessage = onMessage;
        setRealtimeState = onConnectionState;

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

  assert.match(
    widget.root.querySelector('.wayfindr-widget__connection').textContent,
    /Live updates connected/,
  );

  setRealtimeState('unavailable');

  assert.match(
    widget.root.querySelector('.wayfindr-widget__connection').textContent,
    /Using periodic refresh for updates/,
  );

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
    messageSummaries(widget),
    ['VisitorCan you help me?', 'Ada AgentLive hello.'],
  );

  widget.destroy();

  assert.equal(unsubscribed, true);
});

test('renders widget cobrowse prompt only after support requests consent', async () => {
  const dom = new JSDOM([
    '<!doctype html><html><head><title>Install Guide</title></head><body>',
    '<main>',
    '  <h1>Install Guide</h1>',
    '  <p>Public install content.</p>',
    '  <input type="password" value="secret-password">',
    '  <div data-secret>Internal token abc123</div>',
    '</main>',
    '<div id="support"></div>',
    '</body></html>',
  ].join(''), {
    url: 'https://docs.example.test/install',
  });
  const calls = [];
  let cobrowseStatus = {
    status: 'unavailable',
    consent: 'unavailable',
    requested_by: null,
  };

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    cobrowseStatusPollMs: 0,
    storage: memoryStorage(),
    fetch: async (url, options) => {
      calls.push({ url, options });

      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(201, {
          data: {
            site: { public_key: 'site_public_docs', settings: { mask_selectors: ['[data-secret]'] } },
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

      if (url.includes('/api/conversations/WF-TEST123/cobrowse?')) {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: cobrowseStatus,
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/cobrowse-consent')) {
        const payload = JSON.parse(options.body);
        cobrowseStatus = payload.granted
          ? {
              status: 'granted',
              consent: 'granted',
              requested_by: { name: 'Ada Agent' },
            }
          : {
              status: 'revoked',
              consent: 'revoked',
              requested_by: { name: 'Ada Agent' },
            };

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: cobrowseStatus,
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

      if (url.endsWith('/api/conversations/WF-TEST123/cobrowse-page-state')) {
        const payload = JSON.parse(options.body);

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: { status: 'granted' },
            page_state: {
              page_url: payload.page_url,
              title: payload.title,
              viewport_width: payload.viewport_width,
              viewport_height: payload.viewport_height,
              scroll_x: payload.scroll_x,
              scroll_y: payload.scroll_y,
              visibility_state: payload.visibility_state,
              focused: payload.focused,
            },
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/cobrowse-snapshot')) {
        const payload = JSON.parse(options.body);

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: { status: 'granted' },
            snapshot: {
              page_url: payload.page_url,
              title: payload.title,
              html_length: payload.html.length,
              text_length: payload.text.length,
              node_count: payload.node_count,
              masked_count: payload.masked_count,
            },
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/cobrowse-mutations')) {
        const payload = JSON.parse(options.body);

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: { status: 'granted' },
            mutations: {
              last_sequence: payload.sequence,
              batch_count: 1,
              mutation_count: payload.mutations.length,
              dropped_count: payload.dropped_count,
              skipped_count: payload.skipped_count,
              recent_batches_count: 1,
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
  const cobrowseCopy = widget.root.querySelector('.wayfindr-widget__cobrowse-copy');
  const allowButton = widget.root.querySelector('.wayfindr-widget__cobrowse-allow');
  const declineButton = widget.root.querySelector('.wayfindr-widget__cobrowse-decline');

  assert.equal(cobrowse.hidden, true);

  cobrowseStatus = {
    status: 'requested',
    consent: 'requested',
    requested_by: { name: 'Ada Agent' },
  };

  await widget.refreshCobrowseStatus();
  await settle();

  assert.equal(cobrowse.hidden, false);
  assert.match(cobrowseCopy.textContent, /Ada Agent wants to view this page/);
  assert.match(cobrowseCopy.textContent, /sensitive fields masked/);
  assert.equal(allowButton.textContent, 'Allow cobrowse');
  assert.equal(declineButton.textContent, 'Decline');
  assert.equal(declineButton.hidden, false);

  allowButton.dispatchEvent(new dom.window.Event('click', { bubbles: true }));

  await settle();

  const grantCall = calls.find((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-consent'));

  assert.deepEqual(JSON.parse(grantCall.options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    visitor_token: 'visitor-token-123',
    granted: true,
  });
  assert.equal(allowButton.textContent, 'Stop cobrowse');
  assert.equal(declineButton.hidden, true);
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

  const pageStateCall = calls.find((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-page-state'));
  const pageStatePayload = JSON.parse(pageStateCall.options.body);

  assert.equal(pageStatePayload.site_public_key, 'site_public_docs');
  assert.equal(pageStatePayload.anonymous_id, 'anon-browser-123');
  assert.equal(pageStatePayload.visitor_token, 'visitor-token-123');
  assert.equal(pageStatePayload.page_url, 'https://docs.example.test/install');
  assert.equal(pageStatePayload.title, 'Install Guide');
  assert.equal(typeof pageStatePayload.viewport_width, 'number');
  assert.equal(typeof pageStatePayload.viewport_height, 'number');
  assert.equal(typeof pageStatePayload.scroll_x, 'number');
  assert.equal(typeof pageStatePayload.scroll_y, 'number');
  assert.equal(typeof pageStatePayload.visibility_state, 'string');
  assert.equal(typeof pageStatePayload.focused, 'boolean');

  const snapshotCall = calls.find((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-snapshot'));
  const snapshotPayload = JSON.parse(snapshotCall.options.body);

  assert.equal(snapshotPayload.site_public_key, 'site_public_docs');
  assert.equal(snapshotPayload.anonymous_id, 'anon-browser-123');
  assert.equal(snapshotPayload.visitor_token, 'visitor-token-123');
  assert.equal(snapshotPayload.page_url, 'https://docs.example.test/install');
  assert.equal(snapshotPayload.title, 'Install Guide');
  assert.match(snapshotPayload.html, /Public install content/);
  assert.match(snapshotPayload.html, /\[masked\]/);
  assert.equal(snapshotPayload.html.includes('secret-password'), false);
  assert.equal(snapshotPayload.html.includes('Internal token abc123'), false);
  assert.equal(snapshotPayload.html.includes('Can you help me?'), false);
  assert.equal(typeof snapshotPayload.node_count, 'number');
  assert.equal(snapshotPayload.masked_count, 2);

  dom.window.document.querySelector('main p').textContent = 'Updated public install content.';
  dom.window.document.querySelector('[data-secret]').textContent = 'Updated internal token def456';

  await settle();
  await wait(100);

  const mutationCall = calls.find((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-mutations'));
  const mutationPayload = JSON.parse(mutationCall.options.body);

  assert.equal(mutationPayload.site_public_key, 'site_public_docs');
  assert.equal(mutationPayload.anonymous_id, 'anon-browser-123');
  assert.equal(mutationPayload.visitor_token, 'visitor-token-123');
  assert.equal(mutationPayload.page_url, 'https://docs.example.test/install');
  assert.equal(mutationPayload.sequence, 1);
  assert(mutationPayload.mutations.some((mutation) => mutation.type === 'text' && mutation.text === 'Updated public install content.'));
  assert(mutationPayload.mutations.some((mutation) => mutation.type === 'text' && mutation.text === '[masked]'));
  assert.equal(JSON.stringify(mutationPayload).includes('Updated internal token def456'), false);

  allowButton.dispatchEvent(new dom.window.Event('click', { bubbles: true }));

  await settle();

  const revokeCall = calls.filter((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-consent'))[1];

  assert.equal(JSON.parse(revokeCall.options.body).granted, false);
  assert.equal(cobrowse.hidden, true);
  assert.match(widget.root.querySelector('.wayfindr-widget__status').textContent, /Cobrowse consent revoked/);
});

test('reports skipped-only widget mutation batches when the client payload budget is exhausted', async () => {
  const dom = new JSDOM([
    '<!doctype html><html><head><title>Install Guide</title></head><body>',
    '<main>',
    '  <p id="large-copy">Large section.</p>',
    '</main>',
    '<div id="support"></div>',
    '</body></html>',
  ].join(''), {
    url: 'https://docs.example.test/install',
  });
  const calls = [];
  let cobrowseStatus = {
    status: 'requested',
    consent: 'requested',
    requested_by: { name: 'Ada Agent' },
  };

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    cobrowseStatusPollMs: 0,
    mutationPayloadMaxBytes: 500,
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

      if (url.includes('/api/conversations/WF-TEST123/cobrowse?')) {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: cobrowseStatus,
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/cobrowse-consent')) {
        cobrowseStatus = {
          status: 'granted',
          consent: 'granted',
          requested_by: { name: 'Ada Agent' },
        };

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: cobrowseStatus,
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/cobrowse-mutations')) {
        const payload = JSON.parse(options.body);

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: { status: 'granted' },
            mutations: {
              last_sequence: payload.sequence,
              batch_count: 1,
              mutation_count: payload.mutations.length,
              dropped_count: payload.dropped_count,
              skipped_count: payload.skipped_count,
              recent_batches_count: 1,
            },
          },
        });
      }

      return jsonResponse(200, {
        data: {
          conversation: { support_code: 'WF-TEST123', status: 'open' },
          messages: [],
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
  await widget.refreshCobrowseStatus();
  await settle();

  widget.root
    .querySelector('.wayfindr-widget__cobrowse-allow')
    .dispatchEvent(new dom.window.Event('click', { bubbles: true }));

  await settle();

  dom.window.document.querySelector('#large-copy').textContent = 'Huge public update '.repeat(200);

  await settle();
  await wait(100);

  const mutationCall = calls.find((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-mutations'));
  const mutationPayload = JSON.parse(mutationCall.options.body);

  assert.equal(mutationPayload.mutations.length, 0);
  assert.equal(mutationPayload.skipped_count >= 1, true);
  assert(Buffer.byteLength(JSON.stringify(mutationPayload), 'utf8') <= 500);
  assert.equal(JSON.stringify(mutationPayload).includes('Huge public update'), false);

  widget.destroy();
});

test('caps queued widget mutation records before flushing under pressure', async () => {
  const dom = new JSDOM([
    '<!doctype html><html><head><title>Install Guide</title></head><body>',
    '<main>',
    '  <p id="first-copy">First section.</p>',
    '  <p id="second-copy">Second section.</p>',
    '</main>',
    '<div id="support"></div>',
    '</body></html>',
  ].join(''), {
    url: 'https://docs.example.test/install',
  });
  const calls = [];
  let cobrowseStatus = {
    status: 'requested',
    consent: 'requested',
    requested_by: { name: 'Ada Agent' },
  };

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    cobrowseStatusPollMs: 0,
    mutationQueueMaxRecords: 1,
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

      if (url.includes('/api/conversations/WF-TEST123/cobrowse?')) {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: cobrowseStatus,
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/cobrowse-consent')) {
        cobrowseStatus = {
          status: 'granted',
          consent: 'granted',
          requested_by: { name: 'Ada Agent' },
        };

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: cobrowseStatus,
          },
        });
      }

      return jsonResponse(200, {
        data: {
          conversation: { support_code: 'WF-TEST123', status: 'open' },
          messages: [],
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
  await widget.refreshCobrowseStatus();
  await settle();

  widget.root
    .querySelector('.wayfindr-widget__cobrowse-allow')
    .dispatchEvent(new dom.window.Event('click', { bubbles: true }));

  await settle();

  dom.window.document.querySelector('#first-copy').textContent = 'First noisy update.';
  dom.window.document.querySelector('#second-copy').textContent = 'Second useful update.';

  await settle();
  await wait(100);

  const mutationCall = calls.find((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-mutations'));
  const mutationPayload = JSON.parse(mutationCall.options.body);

  assert.equal(mutationPayload.mutations.length, 1);
  assert.equal(mutationPayload.mutations[0].text, 'Second useful update.');
  assert.equal(mutationPayload.skipped_count >= 1, true);
  assert.equal(JSON.stringify(mutationPayload).includes('First noisy update'), false);

  widget.destroy();
});

test('preserves skipped mutation counts that arrive while a report is in flight', async () => {
  const dom = new JSDOM([
    '<!doctype html><html><head><title>Install Guide</title></head><body>',
    '<main>',
    '  <p id="first-copy">First section.</p>',
    '  <p id="second-copy">Second section.</p>',
    '  <p id="third-copy">Third section.</p>',
    '  <p id="fourth-copy">Fourth section.</p>',
    '</main>',
    '<div id="support"></div>',
    '</body></html>',
  ].join(''), {
    url: 'https://docs.example.test/install',
  });
  const mutationPayloads = [];
  let resolveFirstReport = null;
  let cobrowseStatus = {
    status: 'requested',
    consent: 'requested',
    requested_by: { name: 'Ada Agent' },
  };

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    cobrowseStatusPollMs: 0,
    mutationFlushMs: 50,
    mutationQueueMaxRecords: 1,
    storage: memoryStorage(),
    fetch: async (url, options) => {
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

      if (url.includes('/api/conversations/WF-TEST123/cobrowse?')) {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: cobrowseStatus,
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/cobrowse-consent')) {
        cobrowseStatus = {
          status: 'granted',
          consent: 'granted',
          requested_by: { name: 'Ada Agent' },
        };

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: cobrowseStatus,
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/cobrowse-mutations')) {
        const payload = JSON.parse(options.body);
        mutationPayloads.push(payload);

        if (mutationPayloads.length === 1) {
          return new Promise((resolve) => {
            resolveFirstReport = () => resolve(jsonResponse(200, { data: {} }));
          });
        }

        return jsonResponse(200, { data: {} });
      }

      return jsonResponse(200, {
        data: {
          conversation: { support_code: 'WF-TEST123', status: 'open' },
          messages: [],
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
  await widget.refreshCobrowseStatus();
  await settle();

  widget.root
    .querySelector('.wayfindr-widget__cobrowse-allow')
    .dispatchEvent(new dom.window.Event('click', { bubbles: true }));

  await settle();

  dom.window.document.querySelector('#first-copy').textContent = 'First noisy update.';
  dom.window.document.querySelector('#second-copy').textContent = 'Second useful update.';

  await wait(80);
  await settle();

  assert.equal(mutationPayloads.length, 1);
  assert.equal(mutationPayloads[0].mutations[0].text, 'Second useful update.');
  assert.equal(mutationPayloads[0].skipped_count >= 1, true);

  dom.window.document.querySelector('#third-copy').textContent = 'Third noisy update.';
  dom.window.document.querySelector('#fourth-copy').textContent = 'Fourth useful update.';

  await settle();
  resolveFirstReport();
  await wait(80);
  await settle();

  assert.equal(mutationPayloads.length, 2);
  assert.equal(mutationPayloads[1].mutations[0].text, 'Fourth useful update.');
  assert.equal(mutationPayloads[1].skipped_count >= 1, true);

  widget.destroy();
});

test('declines a widget cobrowse request without starting page sharing', async () => {
  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  const calls = [];
  let cobrowseStatus = {
    status: 'requested',
    consent: 'requested',
    requested_by: { name: 'Ada Agent' },
  };

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    cobrowseStatusPollMs: 0,
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

      if (url.includes('/api/conversations/WF-TEST123/cobrowse?')) {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: cobrowseStatus,
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/cobrowse-consent')) {
        const payload = JSON.parse(options.body);
        cobrowseStatus = {
          status: 'revoked',
          consent: 'revoked',
          requested_by: { name: 'Ada Agent' },
        };

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: cobrowseStatus,
          },
        });
      }

      return jsonResponse(200, {
        data: {
          conversation: { support_code: 'WF-TEST123', status: 'open' },
          messages: [],
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
  await widget.refreshCobrowseStatus();
  await settle();

  widget.root
    .querySelector('.wayfindr-widget__cobrowse-decline')
    .dispatchEvent(new dom.window.Event('click', { bubbles: true }));

  await settle();

  const consentCall = calls.find((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-consent'));

  assert.equal(JSON.parse(consentCall.options.body).granted, false);
  assert.equal(widget.root.querySelector('.wayfindr-widget__cobrowse').hidden, true);
  assert.equal(calls.some((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-telemetry')), false);
  assert.equal(calls.some((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-snapshot')), false);
});

test('clears active widget cobrowse notice when support ends the session', async () => {
  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  let cobrowseStatus = {
    status: 'granted',
    consent: 'granted',
    requested_by: { name: 'Ada Agent' },
  };

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    cobrowseStatusPollMs: 0,
    storage: memoryStorage(),
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

      if (url.includes('/api/conversations/WF-TEST123/cobrowse?')) {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: cobrowseStatus,
          },
        });
      }

      return jsonResponse(200, {
        data: {
          conversation: { support_code: 'WF-TEST123', status: 'open' },
          messages: [],
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
  await widget.refreshCobrowseStatus();
  await settle();

  assert.equal(widget.root.querySelector('.wayfindr-widget__cobrowse').hidden, false);

  cobrowseStatus = {
    status: 'ended',
    consent: 'ended',
    requested_by: { name: 'Ada Agent' },
    ended_at: '2026-05-23T14:05:00.000000Z',
  };

  await widget.refreshCobrowseStatus();
  await settle();

  assert.equal(widget.root.querySelector('.wayfindr-widget__cobrowse').hidden, true);
  assert.match(widget.root.querySelector('.wayfindr-widget__status').textContent, /Cobrowse stopped/);
});

function jsonResponse(status, payload) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => payload,
  };
}

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((nextResolve, nextReject) => {
    resolve = nextResolve;
    reject = nextReject;
  });

  return { promise, resolve, reject };
}

async function settle() {
  await new Promise((resolve) => setImmediate(resolve));
  await new Promise((resolve) => setImmediate(resolve));
}

async function wait(milliseconds) {
  await new Promise((resolve) => setTimeout(resolve, milliseconds));
}

function memoryStorage() {
  const values = new Map();

  return {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
  };
}

function messageSummaries(widget) {
  return [...widget.root.querySelectorAll('.wayfindr-widget__message')].map((message) => {
    return message.querySelector('.wayfindr-widget__message-name').textContent
      + message.querySelector('.wayfindr-widget__message-body').textContent;
  });
}

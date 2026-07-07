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

test('exposes stock cobrowse payload budget defaults', () => {
  assert.deepEqual(Wayfindr.cobrowsePayloadBudget, {
    mutationBatchMaxBytes: 60000,
    mutationQueueMaxRecords: 250,
    mutationFlushMs: 50,
    pressureResyncMs: 30000,
    statusPollMs: 5000,
    resyncMaxAttempts: 3,
  });
  assert.equal(Object.isFrozen(Wayfindr.cobrowsePayloadBudget), true);
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

test('injects responsive panel styles so the composer stays reachable on short screens', () => {
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

  const css = dom.window.document.querySelector('#wayfindr-widget-styles').textContent;

  // Panel is a flex column bounded to the viewport so it never grows past the
  // screen; the timeline is the shrinkable area while other rows hold their size.
  assert.ok(css.includes('.wayfindr-widget__panel{display:flex;flex-direction:column;'), 'panel should be a flex column');
  assert.ok(css.includes('max-height:calc(100dvh - 40px)'), 'panel should be bounded to the dynamic viewport height');
  assert.ok(css.includes('overflow:auto}'), 'panel scrolls as a fallback so a short empty state never clips the composer');
  assert.ok(css.includes('.wayfindr-widget__panel>*{flex-shrink:0}'), 'panel rows should not shrink by default');
  assert.ok(css.includes('.wayfindr-widget__panel>.wayfindr-widget__timeline-wrap{flex:0 1 auto;min-height:0}'), 'timeline wrap should be the shrinkable row');
  assert.ok(css.includes('.wayfindr-widget__timeline{display:grid;gap:10px;flex:1 1 auto;min-height:0;max-height:280px;'), 'timeline keeps its compact cap but can shrink and scroll');
  assert.ok(css.includes('.wayfindr-widget__timeline-wrap{position:relative;display:flex;flex-direction:column;min-height:0}'), 'timeline wrap is a flex column');
  // Small-screen tuning: wider panel and a viewport-bounded height.
  assert.ok(css.includes('@media (max-width:480px)'), 'has a small-screen media query');
  assert.ok(css.includes('max-height:calc(100dvh - 24px)'), 'mobile panel is viewport-height bounded');
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

test('marks visitor chat regions for calm assistive announcements', () => {
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
  const typing = widget.root.querySelector('.wayfindr-widget__typing');
  const connection = widget.root.querySelector('.wayfindr-widget__connection');
  const status = widget.root.querySelector('.wayfindr-widget__status');

  assert.equal(timeline.getAttribute('role'), 'log');
  assert.equal(timeline.getAttribute('aria-live'), 'polite');
  assert.equal(timeline.getAttribute('aria-relevant'), 'additions text');
  assert.equal(timeline.getAttribute('aria-atomic'), 'false');
  assert.equal(timeline.getAttribute('aria-label'), 'Conversation messages');

  assert.equal(notice.getAttribute('role'), 'status');
  assert.equal(notice.getAttribute('aria-live'), 'polite');
  assert.equal(notice.getAttribute('aria-atomic'), 'true');

  assert.equal(typing.getAttribute('role'), 'status');
  assert.equal(typing.getAttribute('aria-live'), 'polite');
  assert.equal(typing.getAttribute('aria-atomic'), 'true');

  assert.equal(connection.getAttribute('role'), 'status');
  assert.equal(connection.getAttribute('aria-live'), 'polite');
  assert.equal(connection.getAttribute('aria-atomic'), 'true');

  assert.equal(status.getAttribute('role'), 'status');
  assert.equal(status.getAttribute('aria-live'), 'polite');
  assert.equal(status.getAttribute('aria-atomic'), 'true');
});

test('exposes the widget shell state and closes with Escape', () => {
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

  const launcher = widget.root.querySelector('.wayfindr-widget__launcher');
  const panel = widget.root.querySelector('.wayfindr-widget__panel');
  const textarea = widget.root.querySelector('.wayfindr-widget__textarea');

  assert.equal(launcher.getAttribute('aria-expanded'), 'false');
  assert.equal(launcher.getAttribute('aria-controls'), panel.id);
  assert.ok(panel.id);

  launcher.click();

  assert.equal(panel.hidden, false);
  assert.equal(launcher.hidden, true);
  assert.equal(launcher.getAttribute('aria-expanded'), 'true');
  assert.equal(dom.window.document.activeElement, textarea);

  panel.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

  assert.equal(panel.hidden, true);
  assert.equal(launcher.hidden, false);
  assert.equal(launcher.getAttribute('aria-expanded'), 'false');
  assert.equal(dom.window.document.activeElement, launcher);
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
  const firstMessageBody = JSON.parse(calls[2].options.body);
  // The widget attaches an idempotency key so retries do not duplicate the send.
  assert.equal(typeof firstMessageBody.client_message_id, 'string');
  assert.ok(firstMessageBody.client_message_id.length > 0);
  delete firstMessageBody.client_message_id;
  assert.deepEqual(firstMessageBody, {
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

  await client.fetchMessages('WF-TEST123', { markSeen: true, seenMessageId: 42 });

  assert.equal(calls.length, 1);
  assert.equal(
    calls[0].url,
    'http://127.0.0.1:8000/api/conversations/WF-TEST123/messages?site_public_key=site_public_docs&anonymous_id=anon-browser-123&visitor_token=visitor-token-123&mark_seen=1&seen_message_id=42',
  );
  assert.equal(calls[0].options.method, 'GET');
});

test('reports visitor typing through the public visitor API', async () => {
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
          typing: {
            state: 'typing',
            label: 'Typing now',
          },
        },
      });
    },
  });

  const result = await client.reportTyping('WF-TEST123', true);

  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, 'http://127.0.0.1:8000/api/conversations/WF-TEST123/typing');
  assert.deepEqual(JSON.parse(calls[0].options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    visitor_token: 'visitor-token-123',
    is_typing: true,
  });
  assert.equal(result.conversation.support_code, 'WF-TEST123');
  assert.equal(result.typing.state, 'typing');
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
    mutationSequence: 7,
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
    mutation_sequence: 7,
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
  const typingUpdates = [];
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
  }, undefined, (event) => {
    typingUpdates.push(event);
  });

  subscriptionPayload.events['conversation.message.created']({
    conversation: { support_code: 'WF-TEST123' },
    message: {
      id: 2,
      sender: { kind: 'agent', name: 'Ada Agent' },
      type: 'text',
      body: 'Live hello.',
      created_at: '2026-05-23T14:01:00.000000Z',
    },
  });
  subscriptionPayload.events['conversation.typing.updated']({
    conversation: { support_code: 'WF-TEST123' },
    agent_typing: {
      state: 'typing',
      label: 'Support is typing...',
      updated_at: '2026-05-23T14:01:01.000000Z',
    },
  });

  assert.equal(typeof subscription.unsubscribe, 'function');
  assert.equal(subscriptionPayload.channelName, 'private-conversations.WF-TEST123');
  assert.equal(subscriptionPayload.eventName, 'conversation.message.created');
  assert.deepEqual(Object.keys(subscriptionPayload.events), [
    'conversation.message.created',
    'conversation.typing.updated',
  ]);
  assert.equal(subscriptionPayload.authEndpoint, 'http://127.0.0.1:8000/api/widget/broadcasting/auth');
  assert.deepEqual(subscriptionPayload.authPayload, {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    visitor_token: 'visitor-token-123',
  });
  assert.equal(received.length, 1);
  assert.equal(received[0].message.body, 'Live hello.');
  assert.equal(typingUpdates.length, 1);
  assert.equal(typingUpdates[0].agent_typing.state, 'typing');
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

  const subscription = client.subscribeToConversation('WF-TEST123', () => {}, undefined, () => {});

  assert.deepEqual(boundEvents, [
    'conversation.message.created',
    '.conversation.message.created',
    'conversation.typing.updated',
    '.conversation.typing.updated',
  ]);

  subscription.unsubscribe();

  assert.deepEqual(unboundEvents, [
    'conversation.message.created',
    '.conversation.message.created',
    'conversation.typing.updated',
    '.conversation.typing.updated',
  ]);
});

test('sends the composer on Enter and keeps Shift+Enter for newlines', async () => {
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
    anonymousId: 'anon-enter',
    storage: memoryStorage(),
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    fetch: async (url, options) => {
      calls.push({ url, options });

      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(201, {
          data: {
            site: { public_key: 'site_public_docs', settings: {} },
            visitor: { anonymous_id: 'anon-enter', token: 'visitor-token-enter' },
          },
        });
      }

      if (url.endsWith('/api/conversations')) {
        return jsonResponse(201, { data: { support_code: 'WF-ENTER1', status: 'open' } });
      }

      if (url.includes('/cobrowse?')) {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-ENTER1' },
            cobrowse: { status: 'unavailable', consent: 'unavailable', requested_by: null },
          },
        });
      }

      return jsonResponse(200, {
        data: { conversation: { support_code: 'WF-ENTER1', status: 'open' }, messages: [] },
      });
    },
  });

  widget.open();

  const textarea = widget.root.querySelector('.wayfindr-widget__textarea');
  textarea.value = 'Draft line one';

  // Shift+Enter must not send; it keeps the newline affordance for the visitor.
  textarea.dispatchEvent(
    new dom.window.KeyboardEvent('keydown', { key: 'Enter', shiftKey: true, bubbles: true, cancelable: true }),
  );
  await settle();

  assert.equal(calls.some((call) => call.url.endsWith('/api/conversations')), false);

  // Plain Enter sends the current draft.
  textarea.dispatchEvent(
    new dom.window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
  );
  await settle();

  const conversationCall = calls.find((call) => call.url.endsWith('/api/conversations'));
  assert.ok(conversationCall, 'Enter should start the conversation send');
  assert.equal(JSON.parse(conversationCall.options.body).subject, 'Draft line one');
});

function resumeFetchMock(calls, options) {
  options = options || {};

  return async (url, fetchOptions) => {
    calls.push({ url, options: fetchOptions });

    if (url.endsWith('/api/widget/bootstrap')) {
      return jsonResponse(201, {
        data: {
          site: { public_key: 'site_public_docs', settings: {} },
          visitor: { anonymous_id: 'anon-resume', token: 'visitor-token-resume' },
        },
      });
    }

    if (url.endsWith('/api/conversations')) {
      return jsonResponse(201, { data: { support_code: 'WF-RESUME1', status: 'open' } });
    }

    if (url.includes('/api/conversations/WF-RESUME1/messages') && fetchOptions && fetchOptions.method === 'POST') {
      return jsonResponse(201, {
        data: {
          conversation: { support_code: 'WF-RESUME1' },
          message: {
            sender: { kind: 'visitor', name: 'Visitor' },
            type: 'text',
            body: 'Can you help me?',
            created_at: '2026-05-23T14:00:00.000000Z',
          },
        },
      });
    }

    if (url.includes('/cobrowse?')) {
      return jsonResponse(200, {
        data: {
          conversation: { support_code: 'WF-RESUME1' },
          cobrowse: { status: 'unavailable', consent: 'unavailable', requested_by: null },
        },
      });
    }

    if (options.messages) {
      return options.messages(url);
    }

    return jsonResponse(200, {
      data: {
        conversation: { support_code: 'WF-RESUME1', status: 'open' },
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
            body: 'Happy to help.',
            created_at: '2026-05-23T14:01:00.000000Z',
          },
        ],
      },
    });
  };
}

test('init without a storage option persists to default browser storage', async () => {
  // Regression for the wayfindr.cc embed: Wayfindr.init forwards
  // `storage: options.storage` to the client, so options that never set the
  // key still produced an own "storage" property (value undefined). The old
  // hasOwnProperty check treated that as "storage provided" and skipped the
  // localStorage default — meaning real init embeds never persisted the
  // anonymous id, visitor token, or support code at all.
  const fakeLocalStorage = memoryStorage();
  const hadDocument = Object.prototype.hasOwnProperty.call(globalThis, 'document');
  const originalDocument = globalThis.document;
  const hadLocalStorage = Object.prototype.hasOwnProperty.call(globalThis, 'localStorage');
  const originalLocalStorage = globalThis.localStorage;

  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });

  globalThis.document = dom.window.document;
  globalThis.localStorage = fakeLocalStorage;

  try {
    const calls = [];
    const widget = Wayfindr.init({
      document: dom.window.document,
      location: dom.window.location,
      mount: '#support',
      apiBaseUrl: 'http://127.0.0.1:8000/',
      sitePublicKey: 'site_public_docs',
      mutationFlushMs: 0,
      cobrowseStatusPollMs: 0,
      fetch: resumeFetchMock(calls),
    });

    // Identity is persisted at init through the default storage.
    assert.match(
      fakeLocalStorage.getItem('wayfindr:site_public_docs:anonymous-id') || '',
      /^anon_/,
      'the anonymous id must persist without an explicit storage option',
    );

    widget.open();
    widget.root.querySelector('.wayfindr-widget__textarea').value = 'Can you help me?';
    widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );
    await settle();

    assert.equal(fakeLocalStorage.getItem('wayfindr:site_public_docs:support-code'), 'WF-RESUME1');
    assert.equal(fakeLocalStorage.getItem('wayfindr:site_public_docs:visitor-token'), 'visitor-token-resume');

    widget.destroy();
  } finally {
    if (hadDocument) {
      globalThis.document = originalDocument;
    } else {
      delete globalThis.document;
    }

    if (hadLocalStorage) {
      globalThis.localStorage = originalLocalStorage;
    } else {
      delete globalThis.localStorage;
    }
  }
});

test('ignores inherited storage properties when selecting the default', async () => {
  // An options object can inherit "storage" (Object.create chains, prototype
  // pollution). Only an own, defined property counts as an explicit override;
  // anything inherited must fall through to the browser default.
  const fakeLocalStorage = memoryStorage();
  const hadDocument = Object.prototype.hasOwnProperty.call(globalThis, 'document');
  const originalDocument = globalThis.document;
  const hadLocalStorage = Object.prototype.hasOwnProperty.call(globalThis, 'localStorage');
  const originalLocalStorage = globalThis.localStorage;

  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });

  globalThis.document = dom.window.document;
  globalThis.localStorage = fakeLocalStorage;

  try {
    const bogusStorage = {
      getItem: () => {
        throw new Error('inherited storage must not be used');
      },
      setItem: () => {
        throw new Error('inherited storage must not be used');
      },
    };
    const options = Object.assign(Object.create({ storage: bogusStorage }), {
      document: dom.window.document,
      location: dom.window.location,
      mount: '#support',
      apiBaseUrl: 'http://127.0.0.1:8000/',
      sitePublicKey: 'site_public_docs',
      mutationFlushMs: 0,
      cobrowseStatusPollMs: 0,
      fetch: resumeFetchMock([]),
    });

    const widget = Wayfindr.init(options);

    assert.match(
      fakeLocalStorage.getItem('wayfindr:site_public_docs:anonymous-id') || '',
      /^anon_/,
      'inherited storage must be ignored in favor of the browser default',
    );

    widget.destroy();
  } finally {
    if (hadDocument) {
      globalThis.document = originalDocument;
    } else {
      delete globalThis.document;
    }

    if (hadLocalStorage) {
      globalThis.localStorage = originalLocalStorage;
    } else {
      delete globalThis.localStorage;
    }
  }
});

test('resumes the persisted conversation after a page reload', async () => {
  const storage = memoryStorage();
  const supportCodeKey = 'wayfindr:site_public_docs:support-code';

  // First page view: the visitor starts a conversation.
  const dom1 = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  const firstCalls = [];
  const widget1 = Wayfindr.init({
    document: dom1.window.document,
    location: dom1.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    storage,
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    fetch: resumeFetchMock(firstCalls),
  });

  widget1.open();
  widget1.root.querySelector('.wayfindr-widget__textarea').value = 'Can you help me?';
  widget1.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom1.window.Event('submit', { bubbles: true, cancelable: true }),
  );
  await settle();

  assert.equal(storage.getItem(supportCodeKey), 'WF-RESUME1');
  widget1.destroy();

  // "Reload": a fresh widget with the same storage resumes the conversation
  // without starting a new one.
  const dom2 = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  const secondCalls = [];
  const widget2 = Wayfindr.init({
    document: dom2.window.document,
    location: dom2.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    storage,
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    fetch: resumeFetchMock(secondCalls),
  });

  await settle();

  assert.deepEqual(messageSummaries(widget2), ['VisitorCan you help me?', 'Ada AgentHappy to help.']);
  assert.equal(
    secondCalls.some((call) => call.url.endsWith('/api/conversations') && call.options && call.options.method === 'POST'),
    false,
    'resume must not start a new conversation',
  );
  assert.match(widget2.root.querySelector('.wayfindr-widget__status').textContent, /Conversation restored/);

  widget2.destroy();
});

test('clears a stored support code the server rejects and starts fresh', async () => {
  const storage = memoryStorage();
  const supportCodeKey = 'wayfindr:site_public_docs:support-code';
  storage.setItem(supportCodeKey, 'WF-RESUME1');

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
    storage,
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    fetch: resumeFetchMock(calls, {
      messages: () => jsonResponse(404, { message: 'No visible conversation.' }),
    }),
  });

  await settle();

  assert.equal(storage.getItem(supportCodeKey), null);
  assert.deepEqual(messageSummaries(widget), []);

  widget.destroy();
});

test('keeps a stored support code through transient resume failures', async () => {
  const storage = memoryStorage();
  const supportCodeKey = 'wayfindr:site_public_docs:support-code';
  storage.setItem(supportCodeKey, 'WF-RESUME1');

  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    storage,
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    fetch: async () => {
      throw new TypeError('network down');
    },
  });

  await settle();

  assert.equal(storage.getItem(supportCodeKey), 'WF-RESUME1');

  widget.destroy();
});

test('a message sent while resume is in flight continues the restored conversation', async () => {
  const storage = memoryStorage();
  storage.setItem('wayfindr:site_public_docs:support-code', 'WF-RESUME1');

  // Hold the resume timeline fetch open so the visitor can "beat" it.
  let releaseResume;
  const resumeGate = new Promise((resolve) => {
    releaseResume = resolve;
  });
  let gated = false;

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
    storage,
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    fetch: resumeFetchMock(calls, {
      messages: async () => {
        if (!gated) {
          gated = true;
          await resumeGate;
        }

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-RESUME1', status: 'open' },
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
    }),
  });

  // The visitor sends before resume has resolved.
  widget.open();
  widget.root.querySelector('.wayfindr-widget__textarea').value = 'Quick follow-up';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );

  releaseResume();
  await settle();

  // The send waited for resume: it continued WF-RESUME1 instead of racing it
  // into a brand-new conversation.
  assert.equal(
    calls.some((call) => call.url.endsWith('/api/conversations') && call.options && call.options.method === 'POST'),
    false,
    'no new conversation may be created while resume is in flight',
  );
  assert.equal(
    calls.some((call) => call.url.includes('/api/conversations/WF-RESUME1/messages') && call.options && call.options.method === 'POST'),
    true,
    'the queued message continues the restored conversation',
  );

  widget.destroy();
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

test('renders accepted visitor messages before the next refresh completes', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  const calls = [];
  const timelineRefresh = deferred();

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
        return jsonResponse(201, {
          data: {
            conversation: { support_code: 'WF-TEST123', status: 'open' },
            message: {
              id: 7,
              sender: { kind: 'visitor', name: 'Visitor' },
              type: 'text',
              body: 'Can you help me?',
              created_at: '2026-05-23T14:00:00.000000Z',
            },
          },
        });
      }

      if (path === '/api/conversations/WF-TEST123/messages') {
        return timelineRefresh.promise;
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

  assert.deepEqual(
    messageSummaries(widget),
    ['VisitorCan you help me?'],
  );
  assert.equal(
    calls.filter((call) => new URL(call.url).pathname === '/api/conversations/WF-TEST123/messages' && call.options.method === 'GET').length,
    1,
  );

  timelineRefresh.resolve(jsonResponse(200, {
    data: {
      conversation: {
        support_code: 'WF-TEST123',
        status: 'open',
      },
      messages: [{
        id: 7,
        sender: { kind: 'visitor', name: 'Visitor' },
        type: 'text',
        body: 'Can you help me?',
        created_at: '2026-05-23T14:00:00.000000Z',
      }],
    },
  }));

  await settle();

  assert.deepEqual(
    messageSummaries(widget),
    ['VisitorCan you help me?'],
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

test('shows support delivery status only for visitor messages', async () => {
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

  assert.equal(visitorDelivery.textContent, 'Sent to support');
  assert.equal(visitorDelivery.getAttribute('aria-label'), 'Visitor message sent to support');
  assert.equal(messages[1].querySelector('.wayfindr-widget__message-delivery'), null);
});

test('explains that visitor follow-ups reopen closed conversations', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  let conversationStatus = 'open';
  let messageCount = 2;

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
            support_code: 'WF-CLOSED1',
            status: 'open',
          },
        });
      }

      if (path === '/api/conversations/WF-CLOSED1/messages' && options.method === 'POST') {
        const payload = JSON.parse(options.body);
        const isFollowUp = payload.body === 'Following up.';

        conversationStatus = 'open';
        messageCount = isFollowUp ? 3 : 2;

        return jsonResponse(201, {
          data: {
            conversation: { support_code: 'WF-CLOSED1', status: conversationStatus },
            message: {
              id: isFollowUp ? 3 : 1,
              sender: { kind: 'visitor', name: 'Visitor' },
              type: 'text',
              body: payload.body,
              created_at: isFollowUp ? '2026-05-23T14:05:00.000000Z' : '2026-05-23T13:55:00.000000Z',
            },
          },
        });
      }

      if (path === '/api/conversations/WF-CLOSED1/messages') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-CLOSED1', status: conversationStatus },
            messages: [
              {
                id: 1,
                sender: { kind: 'visitor', name: 'Visitor' },
                type: 'text',
                body: 'Original question.',
                created_at: '2026-05-23T13:55:00.000000Z',
              },
              {
                id: 2,
                sender: { kind: 'agent', name: 'Ada Agent' },
                type: 'text',
                body: 'I closed this out for now.',
                created_at: '2026-05-23T14:00:00.000000Z',
              },
              {
                id: 3,
                sender: { kind: 'visitor', name: 'Visitor' },
                type: 'text',
                body: 'Following up.',
                created_at: '2026-05-23T14:05:00.000000Z',
              },
            ].slice(0, messageCount),
          },
        });
      }

      if (path === '/api/conversations/WF-CLOSED1/cobrowse') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-CLOSED1' },
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

  widget.root.querySelector('.wayfindr-widget__textarea').value = 'Original question.';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );

  await settle();

  conversationStatus = 'closed';
  widget.root.querySelector('.wayfindr-widget__refresh').dispatchEvent(
    new dom.window.Event('click', { bubbles: true }),
  );

  await settle();

  const notice = widget.root.querySelector('.wayfindr-widget__notice');

  assert.equal(notice.hidden, false);
  assert.equal(notice.getAttribute('data-state'), 'closed');
  assert.match(notice.textContent, /This conversation was closed/);
  assert.match(notice.textContent, /Send a new message to reopen it/);
  assert.deepEqual(
    messageSummaries(widget),
    ['VisitorOriginal question.', 'Ada AgentI closed this out for now.'],
  );

  widget.root.querySelector('.wayfindr-widget__textarea').value = 'Following up.';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );

  await settle();

  assert.equal(notice.hidden, true);
  assert.deepEqual(
    messageSummaries(widget),
    ['VisitorOriginal question.', 'Ada AgentI closed this out for now.', 'VisitorFollowing up.'],
  );

  widget.destroy();
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
  const retry = widget.root.querySelector('.wayfindr-widget__notice-retry');

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
  assert.equal(retry.hidden, false);
  assert.equal(retry.textContent, 'Try again');
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

  retry.dispatchEvent(new dom.window.Event('click', { bubbles: true }));

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

  // The original send and its retry must carry the same idempotency key so the
  // server can dedupe a retry whose first response was lost.
  const messagePosts = calls.filter((call) => new URL(call.url).pathname === '/api/conversations/WF-RETRY123/messages' && call.options.method === 'POST');
  const firstKey = JSON.parse(messagePosts[0].options.body).client_message_id;
  const retryKey = JSON.parse(messagePosts[1].options.body).client_message_id;
  assert.equal(typeof firstKey, 'string');
  assert.ok(firstKey.length > 0);
  assert.equal(retryKey, firstKey);

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

test('shows calm connection trouble when fallback message polling fails and clears after refresh', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  let timelineFetches = 0;
  const recoveryResponse = deferred();

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
            support_code: 'WF-POLLFAIL',
            status: 'open',
          },
        });
      }

      if (path === '/api/conversations/WF-POLLFAIL/messages' && options.method === 'POST') {
        return jsonResponse(201, {
          data: {
            conversation: { support_code: 'WF-POLLFAIL' },
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

      if (path === '/api/conversations/WF-POLLFAIL/messages') {
        timelineFetches += 1;

        if (timelineFetches === 2) {
          return jsonResponse(503, {
            message: 'Upstream timeout.',
          });
        }

        if (timelineFetches > 2) {
          return recoveryResponse.promise;
        }

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-POLLFAIL', status: 'open' },
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
                  body: 'I am still here.',
                  created_at: '2026-05-23T14:01:00.000000Z',
                }]
              : [])],
          },
        });
      }

      if (path === '/api/conversations/WF-POLLFAIL/cobrowse') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-POLLFAIL' },
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
  await wait(65);
  await settle();

  assert.deepEqual(messageSummaries(widget), ['VisitorCan you help me?']);
  assert.match(
    widget.root.querySelector('.wayfindr-widget__connection').textContent,
    /Having trouble reaching support/,
  );
  assert.doesNotMatch(
    widget.root.querySelector('.wayfindr-widget__status').textContent,
    /Upstream timeout/,
  );

  widget.root.querySelector('.wayfindr-widget__refresh').dispatchEvent(
    new dom.window.Event('click', { bubbles: true }),
  );

  recoveryResponse.resolve(jsonResponse(200, {
    data: {
      conversation: { support_code: 'WF-POLLFAIL', status: 'open' },
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
        body: 'I am still here.',
        created_at: '2026-05-23T14:01:00.000000Z',
      }],
    },
  }));

  await settle();

  assert.deepEqual(messageSummaries(widget), ['VisitorCan you help me?', 'Ada AgentI am still here.']);
  assert.match(
    widget.root.querySelector('.wayfindr-widget__connection').textContent,
    /Using periodic refresh for updates/,
  );

  widget.destroy();
});

test('shows a calm support typing indicator from fetched message state', async () => {
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
            agent_typing: {
              state: 'typing',
              label: 'Support is typing...',
              updated_at: new Date(Date.now()).toISOString(),
            },
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

  const textarea = widget.root.querySelector('.wayfindr-widget__textarea');
  textarea.value = 'Can you help me?';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );

  await settle();
  await wait(30);
  await settle();

  const typing = widget.root.querySelector('.wayfindr-widget__typing');

  assert.ok(typing);
  assert.equal(typing.hidden, false);
  assert.equal(typing.textContent, 'Support is typing...');

  widget.destroy();
});

test('expires stale support typing copy without waiting for the next refresh', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });

  const now = Date.now();
  const originalDateNow = Date.now;

  Date.now = () => now;

  try {
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
          return jsonResponse(200, {
            data: {
              conversation: { support_code: 'WF-TEST123', status: 'open' },
              messages: [],
              agent_typing: {
                state: 'typing',
                label: 'Support is typing...',
                updated_at: new Date(now - 19000).toISOString(),
              },
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

    const textarea = widget.root.querySelector('.wayfindr-widget__textarea');
    textarea.value = 'Can you help me?';
    widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );

    await settle();
    await wait(30);
    await settle();

    const typing = widget.root.querySelector('.wayfindr-widget__typing');

    assert.equal(typing.hidden, false);
    assert.equal(typing.textContent, 'Support is typing...');

    await wait(1200);
    await settle();

    assert.equal(typing.hidden, true);
    assert.equal(typing.textContent, '');

    widget.destroy();
  } finally {
    Date.now = originalDateNow;
  }
});

test('reports visitor typing once a conversation is active', async () => {
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
    messagePollMs: 0,
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
            messages: [],
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

      if (path === '/api/conversations/WF-TEST123/typing') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            typing: { state: 'typing', label: 'Typing now' },
          },
        });
      }

      throw new Error('Unexpected request ' + url);
    },
  });

  widget.open();

  const textarea = widget.root.querySelector('.wayfindr-widget__textarea');
  textarea.value = 'Can you help me?';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );

  await settle();
  await wait(30);
  await settle();

  calls.length = 0;
  textarea.value = 'Actually, I have one more detail';
  textarea.dispatchEvent(new dom.window.Event('input', { bubbles: true }));

  await settle();

  const typingCall = calls.find((call) => new URL(call.url).pathname === '/api/conversations/WF-TEST123/typing');

  assert.ok(typingCall);
  assert.deepEqual(JSON.parse(typingCall.options.body), {
    site_public_key: 'site_public_docs',
    anonymous_id: 'anon-browser-123',
    visitor_token: 'visitor-token-123',
    is_typing: true,
  });

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
  assert.equal(messageReads.every((value) => value === null), true);

  widget.destroy();
});

test('marks agent replies read only after the rendered message has been visible', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  const calls = [];
  let includeAgentReply = false;

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
    readReceiptDwellMs: 10,
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
            messages: [
              {
                id: 1,
                sender: { kind: 'visitor', name: 'Visitor' },
                type: 'text',
                body: 'Can you help me?',
                created_at: '2026-05-23T14:00:00.000000Z',
              },
              ...(includeAgentReply
                ? [{
                    id: 2,
                    sender: { kind: 'agent', name: 'Ada Agent' },
                    type: 'text',
                    body: 'I can help with that.',
                    created_at: '2026-05-23T14:01:00.000000Z',
                  }]
                : []),
            ],
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

  includeAgentReply = true;
  widget.root.querySelector('.wayfindr-widget__refresh').dispatchEvent(
    new dom.window.Event('click', { bubbles: true }),
  );

  await settle();

  assert.deepEqual(messageSummaries(widget), ['VisitorCan you help me?', 'Ada AgentI can help with that.']);

  await wait(15);
  await settle();

  const messageReads = calls
    .filter((call) => new URL(call.url).pathname === '/api/conversations/WF-TEST123/messages' && call.options.method === 'GET')
    .map((call) => {
      const params = new URL(call.url).searchParams;

      return {
        markSeen: params.get('mark_seen'),
        seenMessageId: params.get('seen_message_id'),
      };
    });

  assert.equal(messageReads.length >= 3, true);
  assert.deepEqual(messageReads[0], { markSeen: null, seenMessageId: null });
  assert.deepEqual(messageReads[1], { markSeen: null, seenMessageId: null });
  assert.deepEqual(messageReads[messageReads.length - 1], { markSeen: '1', seenMessageId: '2' });

  widget.destroy();
});

test('does not retry failed read receipts in a dwell loop', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  const calls = [];
  let includeAgentReply = false;

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
    readReceiptDwellMs: 10,
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
        if (parsed.searchParams.get('mark_seen') === '1') {
          return jsonResponse(503, { message: 'Read receipt temporarily unavailable.' });
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
              ...(includeAgentReply
                ? [{
                    id: 2,
                    sender: { kind: 'agent', name: 'Ada Agent' },
                    type: 'text',
                    body: 'I can help with that.',
                    created_at: '2026-05-23T14:01:00.000000Z',
                  }]
                : []),
            ],
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

  includeAgentReply = true;
  widget.root.querySelector('.wayfindr-widget__refresh').dispatchEvent(
    new dom.window.Event('click', { bubbles: true }),
  );

  await wait(45);
  await settle();

  let readReceiptCalls = calls
    .filter((call) => new URL(call.url).pathname === '/api/conversations/WF-TEST123/messages')
    .filter((call) => new URL(call.url).searchParams.get('mark_seen') === '1');

  assert.equal(readReceiptCalls.length, 1);

  dom.window.document.dispatchEvent(new dom.window.Event('visibilitychange'));

  await wait(15);
  await settle();

  readReceiptCalls = calls
    .filter((call) => new URL(call.url).pathname === '/api/conversations/WF-TEST123/messages')
    .filter((call) => new URL(call.url).searchParams.get('mark_seen') === '1');

  assert.equal(readReceiptCalls.length, 2);

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

test('keeps live connection copy when redundant fallback message polling fails', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  let liveMessage = null;

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    storage: memoryStorage(),
    messagePollMs: 20,
    realtime: {
      subscribe: ({ onMessage }) => {
        liveMessage = onMessage;

        return {
          unsubscribe: () => {},
        };
      },
    },
    fetch: async (url, options = {}) => {
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
            support_code: 'WF-LIVEPOLL',
            status: 'open',
          },
        });
      }

      if (path === '/api/conversations/WF-LIVEPOLL/messages' && options.method === 'POST') {
        return jsonResponse(201, {
          data: {
            conversation: { support_code: 'WF-LIVEPOLL' },
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

      if (path === '/api/conversations/WF-LIVEPOLL/messages') {
        return jsonResponse(503, {
          message: 'Temporary polling outage.',
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
  await wait(35);
  await settle();

  assert.match(
    widget.root.querySelector('.wayfindr-widget__connection').textContent,
    /Live updates connected/,
  );

  liveMessage({
    conversation: { support_code: 'WF-LIVEPOLL' },
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
  assert.match(
    widget.root.querySelector('.wayfindr-widget__connection').textContent,
    /Live updates connected/,
  );

  widget.destroy();
});

test('renders live support typing updates from the realtime subscription', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  let subscriptionPayload = null;

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    storage: memoryStorage(),
    realtime: {
      subscribe: (payload) => {
        subscriptionPayload = payload;

        return {
          unsubscribe: () => {},
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

  subscriptionPayload.events['conversation.typing.updated']({
    conversation: { support_code: 'WF-TEST123' },
    agent_typing: {
      state: 'typing',
      label: 'Support is typing...',
      updated_at: '2026-05-23T14:01:00.000000Z',
    },
  });

  const typing = widget.root.querySelector('.wayfindr-widget__typing');

  assert.equal(typing.hidden, false);
  assert.equal(typing.textContent, 'Support is typing...');

  subscriptionPayload.events['conversation.typing.updated']({
    conversation: { support_code: 'WF-TEST123' },
    agent_typing: {
      state: 'idle',
      label: null,
      updated_at: null,
    },
  });

  assert.equal(typing.hidden, true);
  assert.equal(typing.textContent, '');

  widget.destroy();
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
  const textarea = widget.root.querySelector('.wayfindr-widget__textarea');

  assert.equal(cobrowse.hidden, true);
  assert.equal(cobrowse.getAttribute('role'), 'group');
  assert.equal(cobrowse.getAttribute('aria-label'), 'Cobrowse request');
  assert.equal(cobrowse.getAttribute('aria-describedby'), cobrowseCopy.id);
  assert.ok(cobrowseCopy.id);
  assert.equal(cobrowseCopy.getAttribute('role'), 'status');
  assert.equal(cobrowseCopy.getAttribute('aria-live'), 'polite');
  assert.equal(cobrowseCopy.getAttribute('aria-atomic'), 'true');

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
  assert.equal(dom.window.document.activeElement, allowButton);

  textarea.focus();
  await widget.refreshCobrowseStatus();
  await settle();

  assert.equal(dom.window.document.activeElement, textarea);

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

test('defers cobrowse consent focus until a closed widget is reopened', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
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
      const parsed = new URL(url);

      if (parsed.pathname === '/api/widget/bootstrap') {
        return jsonResponse(201, {
          data: {
            site: { public_key: 'site_public_docs', settings: {} },
            visitor: { anonymous_id: 'anon-browser-123', token: 'visitor-token-123' },
          },
        });
      }

      if (parsed.pathname === '/api/conversations') {
        return jsonResponse(201, {
          data: {
            support_code: 'WF-TEST123',
            status: 'open',
          },
        });
      }

      if (parsed.pathname === '/api/conversations/WF-TEST123/messages' && options.method === 'POST') {
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

      if (parsed.pathname === '/api/conversations/WF-TEST123/messages') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123', status: 'open' },
            messages: [],
          },
        });
      }

      if (parsed.pathname === '/api/conversations/WF-TEST123/cobrowse') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: cobrowseStatus,
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

  const launcher = widget.root.querySelector('.wayfindr-widget__launcher');
  const allowButton = widget.root.querySelector('.wayfindr-widget__cobrowse-allow');
  const textarea = widget.root.querySelector('.wayfindr-widget__textarea');

  assert.equal(dom.window.document.activeElement, launcher);

  cobrowseStatus = {
    status: 'requested',
    consent: 'requested',
    requested_by: { name: 'Ada Agent' },
  };

  await widget.refreshCobrowseStatus();
  await settle();

  widget.open();

  assert.equal(dom.window.document.activeElement, allowButton);

  textarea.focus();
  await widget.refreshCobrowseStatus();
  await settle();

  assert.equal(dom.window.document.activeElement, textarea);

  widget.destroy();
});

test('marks the launcher while cobrowse is active so closed-panel sharing stays visible', async () => {
  const dom = new JSDOM(
    '<!doctype html><html><head><title>Install Guide</title></head><body><main><p>Public install content.</p></main><div id="support"></div></body></html>',
    { url: 'https://docs.example.test/install' },
  );

  let cobrowseStatus = { status: 'unavailable', consent: 'unavailable', requested_by: null };

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
        return jsonResponse(201, { data: { support_code: 'WF-TEST123', status: 'open' } });
      }

      if (url.includes('/cobrowse?')) {
        return jsonResponse(200, { data: { conversation: { support_code: 'WF-TEST123' }, cobrowse: cobrowseStatus } });
      }

      return jsonResponse(200, { data: {} });
    },
  });

  widget.open();
  widget.root.querySelector('.wayfindr-widget__textarea').value = 'Can you help me?';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );
  await settle();

  const launcher = widget.root.querySelector('.wayfindr-widget__launcher');

  // A pending request is not active sharing, so the launcher stays unmarked.
  cobrowseStatus = { status: 'requested', consent: 'requested', requested_by: { name: 'Ada Agent' } };
  await widget.refreshCobrowseStatus();
  await settle();
  assert.equal(launcher.hasAttribute('data-cobrowse-active'), false);

  // Granted: the launcher carries a persistent, labelled active-sharing cue.
  cobrowseStatus = { status: 'granted', consent: 'granted', requested_by: { name: 'Ada Agent' } };
  await widget.refreshCobrowseStatus();
  await settle();
  assert.equal(launcher.getAttribute('data-cobrowse-active'), 'true');
  assert.match(launcher.getAttribute('aria-label'), /sharing this page with support/);

  // Ended: the cue clears so a stale "sharing" indicator never lingers.
  cobrowseStatus = { status: 'ended', consent: 'ended', requested_by: { name: 'Ada Agent' } };
  await widget.refreshCobrowseStatus();
  await settle();
  assert.equal(launcher.hasAttribute('data-cobrowse-active'), false);
  assert.equal(launcher.hasAttribute('aria-label'), false);

  widget.destroy();
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

test('renders calm visitor cobrowse copy from the status payload', async () => {
  const dom = new JSDOM([
    '<!doctype html><html><head><title>Install Guide</title></head><body>',
    '<main><p>Public install content.</p></main>',
    '<div id="support"></div>',
    '</body></html>',
  ].join(''), {
    url: 'https://docs.example.test/install',
  });
  const visitorNotice = {
    state: 'degraded',
    message: 'Cobrowse is catching up with recent page changes. Sensitive fields stay masked.',
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
            cobrowse: {
              status: 'granted',
              consent: 'granted',
              requested_by: { name: 'Ada Agent' },
              visitor_notice: visitorNotice,
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

  const cobrowseCopy = widget.root.querySelector('.wayfindr-widget__cobrowse-copy');

  assert.equal(widget.root.querySelector('.wayfindr-widget__cobrowse').hidden, false);
  assert.equal(cobrowseCopy.textContent, visitorNotice.message);
  assert.doesNotMatch(cobrowseCopy.textContent, /dropped|skipped|mutation|batch/i);

  widget.destroy();
});

test('resyncs a sanitized cobrowse snapshot after skipped mutation pressure', async () => {
  const dom = new JSDOM([
    '<!doctype html><html><head><title>Install Guide</title></head><body>',
    '<main>',
    '  <p id="large-copy">Large section.</p>',
    '  <input id="password" type="password" value="secret-password">',
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
  const snapshotCalls = calls.filter((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-snapshot'));
  const resyncSnapshotPayload = JSON.parse(snapshotCalls[snapshotCalls.length - 1].options.body);

  assert.equal(JSON.parse(mutationCall.options.body).skipped_count >= 1, true);
  assert.equal(snapshotCalls.length, 2);
  assert.equal(resyncSnapshotPayload.mutation_sequence, JSON.parse(mutationCall.options.body).sequence);
  assert.match(resyncSnapshotPayload.html, /Huge public update/);
  assert.match(resyncSnapshotPayload.html, /\[masked\]/);
  assert.equal(resyncSnapshotPayload.html.includes('secret-password'), false);

  widget.destroy();
});

test('shows calm visitor copy while cobrowse catches up after mutation pressure', async () => {
  const dom = new JSDOM([
    '<!doctype html><html><head><title>Install Guide</title></head><body>',
    '<main>',
    '  <p id="large-copy">Large section.</p>',
    '  <input id="password" type="password" value="secret-password">',
    '</main>',
    '<div id="support"></div>',
    '</body></html>',
  ].join(''), {
    url: 'https://docs.example.test/install',
  });
  const recoverySnapshot = deferred();
  let snapshotCalls = 0;
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

      if (url.endsWith('/api/conversations/WF-TEST123/cobrowse-snapshot')) {
        snapshotCalls += 1;

        if (snapshotCalls === 2) {
          return recoverySnapshot.promise;
        }
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

  await wait(100);

  const cobrowseCopy = widget.root.querySelector('.wayfindr-widget__cobrowse-copy');

  assert.equal(snapshotCalls, 2);
  assert.match(cobrowseCopy.textContent, /Wayfindr is catching up/);
  assert.doesNotMatch(cobrowseCopy.textContent, /skipped|dropped|mutation|batch/i);

  recoverySnapshot.resolve(jsonResponse(200, {
    data: {
      conversation: { support_code: 'WF-TEST123', status: 'open' },
      cobrowse: cobrowseStatus,
    },
  }));

  await settle();

  assert.equal(cobrowseCopy.textContent, 'Cobrowse is active. Sensitive fields stay masked.');

  widget.destroy();
});

test('responds once to an agent cobrowse resync request with fresh page state and snapshot', async () => {
  const dom = new JSDOM([
    '<!doctype html><html><head><title>Install Guide</title></head><body>',
    '<main>',
    '  <p id="status-copy">Original public install content.</p>',
    '  <input id="password" type="password" value="secret-password">',
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
    resync: {
      requested: false,
      request_id: null,
      requested_at: null,
      requested_by: null,
    },
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
        cobrowseStatus = {
          status: 'granted',
          consent: 'granted',
          requested_by: { name: 'Ada Agent' },
          resync: {
            requested: false,
            request_id: null,
            requested_at: null,
            requested_by: null,
          },
        };

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: cobrowseStatus,
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
              resync_request_id: payload.resync_request_id,
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

  dom.window.document.querySelector('#status-copy').textContent = 'Fresh public install content.';
  cobrowseStatus = {
    status: 'granted',
    consent: 'granted',
    requested_by: { name: 'Ada Agent' },
    resync: {
      requested: true,
      request_id: 'resync_123',
      requested_at: '2026-06-18T15:10:00.000000Z',
      requested_by: { name: 'Ada Agent' },
    },
  };

  await widget.refreshCobrowseStatus();
  await settle();

  const pageStateCalls = calls.filter((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-page-state'));
  const snapshotCalls = calls.filter((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-snapshot'));
  const resyncSnapshotPayload = JSON.parse(snapshotCalls[snapshotCalls.length - 1].options.body);

  assert.equal(pageStateCalls.length, 2);
  assert.equal(snapshotCalls.length, 2);
  assert.equal(resyncSnapshotPayload.resync_request_id, 'resync_123');
  assert.match(resyncSnapshotPayload.html, /Fresh public install content/);
  assert.match(resyncSnapshotPayload.html, /\[masked\]/);
  assert.equal(resyncSnapshotPayload.html.includes('secret-password'), false);

  await widget.refreshCobrowseStatus();
  await settle();

  assert.equal(
    calls.filter((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-snapshot')).length,
    2,
  );

  widget.destroy();
});

test('stops retrying one failing agent cobrowse resync request after the configured attempt bound', async () => {
  const dom = new JSDOM([
    '<!doctype html><html><head><title>Install Guide</title></head><body>',
    '<main>',
    '  <p id="status-copy">Public install content.</p>',
    '  <input id="password" type="password" value="secret-password">',
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
    resync: {
      requested: false,
      request_id: null,
      requested_at: null,
      requested_by: null,
    },
  };

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    cobrowseStatusPollMs: 0,
    cobrowseResyncMaxAttempts: 2,
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
          resync: {
            requested: false,
            request_id: null,
            requested_at: null,
            requested_by: null,
          },
        };

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: cobrowseStatus,
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/cobrowse-page-state')) {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: { status: 'granted' },
            page_state: {},
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/cobrowse-snapshot')) {
        const payload = JSON.parse(options.body);

        if (payload.resync_request_id) {
          return jsonResponse(503, {
            message: 'Snapshot intake unavailable.',
          });
        }

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: { status: 'granted' },
            snapshot: {
              page_url: payload.page_url,
              title: payload.title,
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

  cobrowseStatus = {
    status: 'granted',
    consent: 'granted',
    requested_by: { name: 'Ada Agent' },
    resync: {
      requested: true,
      request_id: 'resync_retry',
      requested_at: '2026-06-18T15:10:00.000000Z',
      requested_by: { name: 'Ada Agent' },
    },
  };

  await widget.refreshCobrowseStatus();
  await settle();
  await widget.refreshCobrowseStatus();
  await settle();
  await widget.refreshCobrowseStatus();
  await settle();
  await widget.refreshCobrowseStatus();
  await settle();

  const resyncSnapshotPayloads = calls
    .filter((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-snapshot'))
    .map((call) => JSON.parse(call.options.body))
    .filter((payload) => payload.resync_request_id === 'resync_retry');
  const exhaustedTelemetryPayloads = calls
    .filter((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-telemetry'))
    .map((call) => JSON.parse(call.options.body))
    .filter((payload) => payload.resync_attempts_exhausted === true);

  assert.equal(resyncSnapshotPayloads.length, 2);
  assert.match(resyncSnapshotPayloads[0].html, /Public install content/);
  assert.match(resyncSnapshotPayloads[0].html, /\[masked\]/);
  assert.equal(resyncSnapshotPayloads[0].html.includes('secret-password'), false);
  assert.deepEqual(
    exhaustedTelemetryPayloads.map((payload) => payload.resync_request_id),
    ['resync_retry'],
  );

  widget.destroy();
});

test('resets bounded agent cobrowse resync retry attempts for a new request id', async () => {
  const dom = new JSDOM([
    '<!doctype html><html><head><title>Install Guide</title></head><body>',
    '<main>',
    '  <p id="status-copy">Public install content.</p>',
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
    resync: {
      requested: false,
      request_id: null,
      requested_at: null,
      requested_by: null,
    },
  };

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    cobrowseStatusPollMs: 0,
    cobrowseResyncMaxAttempts: 1,
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
          resync: {
            requested: false,
            request_id: null,
            requested_at: null,
            requested_by: null,
          },
        };

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: cobrowseStatus,
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/cobrowse-page-state')) {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: { status: 'granted' },
            page_state: {},
          },
        });
      }

      if (url.endsWith('/api/conversations/WF-TEST123/cobrowse-snapshot')) {
        const payload = JSON.parse(options.body);

        if (payload.resync_request_id) {
          return jsonResponse(503, {
            message: 'Snapshot intake unavailable.',
          });
        }

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: { status: 'granted' },
            snapshot: {
              page_url: payload.page_url,
              title: payload.title,
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

  cobrowseStatus = {
    status: 'granted',
    consent: 'granted',
    requested_by: { name: 'Ada Agent' },
    resync: {
      requested: true,
      request_id: 'resync_first',
      requested_at: '2026-06-18T15:10:00.000000Z',
      requested_by: { name: 'Ada Agent' },
    },
  };

  await widget.refreshCobrowseStatus();
  await settle();
  await widget.refreshCobrowseStatus();
  await settle();

  cobrowseStatus = {
    status: 'granted',
    consent: 'granted',
    requested_by: { name: 'Ada Agent' },
    resync: {
      requested: true,
      request_id: 'resync_second',
      requested_at: '2026-06-18T15:11:00.000000Z',
      requested_by: { name: 'Ada Agent' },
    },
  };

  await widget.refreshCobrowseStatus();
  await settle();

  const resyncSnapshotPayloads = calls
    .filter((call) => call.url.endsWith('/api/conversations/WF-TEST123/cobrowse-snapshot'))
    .map((call) => JSON.parse(call.options.body))
    .filter((payload) => payload.resync_request_id);

  assert.deepEqual(
    resyncSnapshotPayloads.map((payload) => payload.resync_request_id),
    ['resync_first', 'resync_second'],
  );

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
    removeItem: (key) => values.delete(key),
  };
}

function messageSummaries(widget) {
  return [...widget.root.querySelectorAll('.wayfindr-widget__message')].map((message) => {
    return message.querySelector('.wayfindr-widget__message-name').textContent
      + message.querySelector('.wayfindr-widget__message-body').textContent;
  });
}

test('separates widget messages by day with calm date dividers', async () => {
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
        return jsonResponse(201, { data: { support_code: 'WF-TEST123', status: 'open' } });
      }

      if (path === '/api/conversations/WF-TEST123/cobrowse') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: { status: 'unavailable', consent: 'unavailable', requested_by: null },
          },
        });
      }

      if (path === '/api/conversations/WF-TEST123/messages') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123', status: 'open' },
            messages: [
              { id: 1, sender: { kind: 'visitor', name: 'Visitor' }, type: 'text', body: 'First thought.', created_at: '2026-05-22T14:00:00.000000Z' },
              { id: 2, sender: { kind: 'visitor', name: 'Visitor' }, type: 'text', body: 'Same day follow-up.', created_at: '2026-05-22T14:01:00.000000Z' },
              { id: 3, sender: { kind: 'agent', name: 'Ada Agent' }, type: 'text', body: 'Next day reply.', created_at: '2026-05-23T09:00:00.000000Z' },
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

  const timeline = widget.root.querySelector('.wayfindr-widget__timeline');
  const separators = [...timeline.querySelectorAll('.wayfindr-widget__day-separator')];
  const messages = [...timeline.querySelectorAll('.wayfindr-widget__message')];

  // One divider per distinct day, not per message.
  assert.equal(separators.length, 2);
  assert.equal(messages.length, 3);
  // A divider opens the timeline, before the first message.
  assert.equal(timeline.firstElementChild.classList.contains('wayfindr-widget__day-separator'), true);
  // Dividers carry the machine-readable day in <time datetime>.
  assert.equal(separators[0].querySelector('time').dateTime, '2026-05-22');
  assert.equal(separators[1].querySelector('time').dateTime, '2026-05-23');
});

function jumpCueWidget(dom, getIncludeNewMessage) {
  return Wayfindr.init({
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
        return jsonResponse(201, { data: { support_code: 'WF-TEST123', status: 'open' } });
      }

      if (path === '/api/conversations/WF-TEST123/cobrowse') {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: { status: 'unavailable', consent: 'unavailable', requested_by: null },
          },
        });
      }

      if (path === '/api/conversations/WF-TEST123/messages') {
        const messages = [
          { id: 1, sender: { kind: 'visitor', name: 'Visitor' }, type: 'text', body: 'First thought.', created_at: '2026-05-23T14:00:00.000000Z' },
        ];

        if (getIncludeNewMessage()) {
          messages.push({ id: 2, sender: { kind: 'agent', name: 'Ada Agent' }, type: 'text', body: 'Newer reply.', created_at: '2026-05-23T14:05:00.000000Z' });
        }

        return jsonResponse(200, { data: { conversation: { support_code: 'WF-TEST123', status: 'open' }, messages } });
      }

      throw new Error('Unexpected request ' + url);
    },
  });
}

function stubScroll(element, scrollHeight, clientHeight, scrollTop) {
  let top = scrollTop;

  Object.defineProperty(element, 'scrollHeight', { configurable: true, get: () => scrollHeight });
  Object.defineProperty(element, 'clientHeight', { configurable: true, get: () => clientHeight });
  Object.defineProperty(element, 'scrollTop', { configurable: true, get: () => top, set: (value) => { top = value; } });
}

test('shows a jump-to-latest cue when a reply arrives while the visitor is scrolled up', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  let includeNewMessage = false;
  const widget = jumpCueWidget(dom, () => includeNewMessage);

  widget.open();
  widget.root.querySelector('.wayfindr-widget__textarea').value = 'First thought.';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );
  await settle();

  const timeline = widget.root.querySelector('.wayfindr-widget__timeline');
  const jump = widget.root.querySelector('.wayfindr-widget__jump');

  // Nothing to jump to right after the first message lands.
  assert.equal(jump.hidden, true);

  // Simulate the visitor having scrolled up, then a new agent reply arrives.
  stubScroll(timeline, 1000, 280, 0);
  includeNewMessage = true;
  widget.root.querySelector('.wayfindr-widget__refresh').dispatchEvent(new dom.window.Event('click', { bubbles: true }));
  await settle();

  assert.equal(jump.hidden, false);

  // Activating the cue jumps to the latest message and dismisses the cue.
  jump.dispatchEvent(new dom.window.Event('click', { bubbles: true }));
  assert.equal(jump.hidden, true);
  assert.equal(timeline.scrollTop, 1000);
});

test('keeps the latest reply in view without a cue when already at the bottom', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  let includeNewMessage = false;
  const widget = jumpCueWidget(dom, () => includeNewMessage);

  widget.open();
  widget.root.querySelector('.wayfindr-widget__textarea').value = 'First thought.';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );
  await settle();

  const timeline = widget.root.querySelector('.wayfindr-widget__timeline');
  const jump = widget.root.querySelector('.wayfindr-widget__jump');

  // Visitor is at the bottom when the new reply arrives.
  stubScroll(timeline, 1000, 280, 720);
  includeNewMessage = true;
  widget.root.querySelector('.wayfindr-widget__refresh').dispatchEvent(new dom.window.Event('click', { bubbles: true }));
  await settle();

  assert.equal(jump.hidden, true);
  assert.equal(timeline.scrollTop, 1000);
});

test('does not yank a scrolled-up visitor when a refresh brings no new messages', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });
  // includeNewMessage stays false, so the last message remains the visitor's own
  // and refreshes return the same list (no growth) -- the regression Codex flagged.
  const widget = jumpCueWidget(dom, () => false);

  widget.open();
  widget.root.querySelector('.wayfindr-widget__textarea').value = 'First thought.';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );
  await settle();

  const timeline = widget.root.querySelector('.wayfindr-widget__timeline');
  const jump = widget.root.querySelector('.wayfindr-widget__jump');

  // Visitor scrolls up to reread; the last message in the thread is their own.
  stubScroll(timeline, 1000, 280, 0);
  widget.root.querySelector('.wayfindr-widget__refresh').dispatchEvent(new dom.window.Event('click', { bubbles: true }));
  await settle();

  // No new messages, so the visitor stays where they scrolled and sees no cue.
  assert.equal(jump.hidden, true);
  assert.equal(timeline.scrollTop, 0);
});

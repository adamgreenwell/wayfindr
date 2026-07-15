const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

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

function deferred() {
  let resolve;
  const promise = new Promise((r) => {
    resolve = r;
  });

  return { promise, resolve };
}

function memoryStorage(seed) {
  const store = Object.assign({}, seed);

  return {
    getItem: (key) => (key in store ? store[key] : null),
    setItem: (key, value) => {
      store[key] = String(value);
    },
    removeItem: (key) => {
      delete store[key];
    },
  };
}

// --- Client API contract --------------------------------------------------

test('uploadAttachment posts multipart form data to the attachments endpoint', async () => {
  const calls = [];
  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    visitorToken: 'visitor-token-123',
    fetch: async (url, options) => {
      calls.push({ url, options });

      return jsonResponse(201, {
        data: { attachment: { id: 42, filename: 'shot.png', mime_type: 'image/png', size_bytes: 2048, is_image: true, status: 'ready' } },
      });
    },
  });

  const file = new globalThis.Blob(['imagedata'], { type: 'image/png' });
  const result = await client.uploadAttachment('WF-TEST123', file);

  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, 'http://127.0.0.1:8000/api/conversations/WF-TEST123/attachments');
  assert.equal(calls[0].options.method, 'POST');
  // No explicit Content-Type — the browser adds the multipart boundary.
  assert.equal(calls[0].options.headers['Content-Type'], undefined);

  const form = calls[0].options.body;
  assert.equal(form.get('site_public_key'), 'site_public_docs');
  assert.equal(form.get('anonymous_id'), 'anon-browser-123');
  assert.equal(form.get('visitor_token'), 'visitor-token-123');
  assert.ok(form.get('file'), 'the file part must be present');
  assert.equal(result.attachment.id, 42);
});

test('sendMessage includes attachment_ids only when provided', async () => {
  const calls = [];
  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    visitorToken: 'visitor-token-123',
    fetch: async (url, options) => {
      calls.push({ url, options });

      return jsonResponse(201, { data: { conversation: { support_code: 'WF-TEST123' }, message: { id: 1 } } });
    },
  });

  await client.sendMessage('WF-TEST123', 'here you go', 'cid-1', [5, 6]);
  await client.sendMessage('WF-TEST123', 'text only', 'cid-2');

  assert.deepEqual(JSON.parse(calls[0].options.body).attachment_ids, [5, 6]);
  assert.equal(Object.prototype.hasOwnProperty.call(JSON.parse(calls[1].options.body), 'attachment_ids'), false);
});

test('sendMessage may omit the body when attachments carry the message', async () => {
  const calls = [];
  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    visitorToken: 'visitor-token-123',
    fetch: async (url, options) => {
      calls.push({ url, options });

      return jsonResponse(201, { data: { conversation: { support_code: 'WF-TEST123' }, message: { id: 1 } } });
    },
  });

  await client.sendMessage('WF-TEST123', '', 'cid-1', [7]);

  const body = JSON.parse(calls[0].options.body);
  assert.equal(Object.prototype.hasOwnProperty.call(body, 'body'), false);
  assert.deepEqual(body.attachment_ids, [7]);
});

test('deleteAttachment issues a scoped DELETE to the attachment url', async () => {
  const calls = [];
  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    visitorToken: 'visitor-token-123',
    fetch: async (url, options) => {
      calls.push({ url, options });

      return jsonResponse(204, {});
    },
  });

  await client.deleteAttachment('WF-TEST123', 42);

  assert.equal(calls.length, 1);
  assert.equal(calls[0].options.method, 'DELETE');
  assert.match(calls[0].url, /\/api\/conversations\/WF-TEST123\/attachments\/42\?site_public_key=site_public_docs&anonymous_id=anon-browser-123&visitor_token=visitor-token-123/);
});

test('attachmentDownloadUrl carries the visitor session in the query string', () => {
  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-browser-123',
    visitorToken: 'visitor-token-123',
    fetch: async () => jsonResponse(200, { data: {} }),
  });

  assert.equal(
    client.attachmentDownloadUrl('WF-TEST123', 42),
    'http://127.0.0.1:8000/api/conversations/WF-TEST123/attachments/42?site_public_key=site_public_docs&anonymous_id=anon-browser-123&visitor_token=visitor-token-123',
  );
});

// --- Transcript rendering -------------------------------------------------

function resumeFetchWithAttachments(messages) {
  return async (url) => {
    if (url.endsWith('/api/widget/bootstrap')) {
      return jsonResponse(200, {
        data: { site: { public_key: 'site_public_docs', settings: {} }, visitor: { anonymous_id: 'anon-docs', token: 'visitor-token-docs' } },
      });
    }

    if (url.includes('/cobrowse')) {
      return jsonResponse(200, { data: { cobrowse: { state: 'unavailable' } } });
    }

    if (url.includes('/messages')) {
      return jsonResponse(200, { data: { conversation: { support_code: 'WF-DOCS', status: 'open' }, messages } });
    }

    return jsonResponse(200, { data: {} });
  };
}

test('renders received image and file attachments in the transcript', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', { url: 'https://docs.example.test/' });
  const storage = memoryStorage({
    'wayfindr:site_public_docs:anonymous-id': 'anon-docs',
    'wayfindr:site_public_docs:visitor-token': 'visitor-token-docs',
    'wayfindr:site_public_docs:support-code': 'WF-DOCS',
  });

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    storage,
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: resumeFetchWithAttachments([
      {
        id: 10,
        sender: { kind: 'agent', name: 'Ada' },
        type: 'text',
        body: 'Here is the diagram.',
        attachments: [{ id: 100, filename: 'diagram.png', mime_type: 'image/png', size_bytes: 4096, is_image: true, status: 'ready' }],
        created_at: '2026-07-15T10:00:00.000000Z',
      },
      {
        id: 11,
        sender: { kind: 'visitor', name: 'Visitor' },
        type: 'text',
        body: null,
        attachments: [{ id: 101, filename: 'log.txt', mime_type: 'text/plain', size_bytes: 500, is_image: false, status: 'ready' }],
        created_at: '2026-07-15T10:01:00.000000Z',
      },
    ]),
  });

  widget.open();
  await settle();

  const img = widget.root.querySelector('.wayfindr-widget__attachment-image');
  assert.ok(img, 'the image attachment renders inline');
  assert.match(img.getAttribute('src'), /\/api\/conversations\/WF-DOCS\/attachments\/100\?/);
  assert.equal(img.getAttribute('alt'), 'diagram.png');

  const fileLink = widget.root.querySelector('.wayfindr-widget__attachment--file');
  assert.ok(fileLink, 'the non-image attachment renders as a file row');
  assert.match(fileLink.getAttribute('href'), /\/attachments\/101\?/);
  assert.equal(fileLink.getAttribute('rel'), 'noopener noreferrer');
  assert.match(fileLink.textContent, /log\.txt/);

  widget.destroy();
});

test('an unchanged refresh does not recreate image elements (so images are not refetched)', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', { url: 'https://docs.example.test/' });
  const storage = memoryStorage({
    'wayfindr:site_public_docs:anonymous-id': 'anon-docs',
    'wayfindr:site_public_docs:visitor-token': 'visitor-token-docs',
    'wayfindr:site_public_docs:support-code': 'WF-DOCS',
  });

  const messages = [
    {
      id: 10,
      sender: { kind: 'agent', name: 'Ada' },
      type: 'text',
      body: 'Here is the diagram.',
      attachments: [{ id: 100, filename: 'diagram.png', mime_type: 'image/png', size_bytes: 4096, is_image: true, status: 'ready' }],
      created_at: '2026-07-15T10:00:00.000000Z',
    },
  ];

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    storage,
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: resumeFetchWithAttachments(messages),
  });

  widget.open();
  await settle();

  const firstImg = widget.root.querySelector('.wayfindr-widget__attachment-image');
  assert.ok(firstImg, 'the image renders on first load');

  // A manual refresh brings the identical message list.
  widget.root.querySelector('.wayfindr-widget__refresh').dispatchEvent(new dom.window.Event('click', { bubbles: true }));
  await settle();

  const secondImg = widget.root.querySelector('.wayfindr-widget__attachment-image');
  assert.equal(secondImg, firstImg, 'the same <img> node is preserved, so the browser does not refetch it');

  widget.destroy();
});

test('a live realtime message renders its attachments immediately', async () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', { url: 'https://docs.example.test/' });
  const storage = memoryStorage({
    'wayfindr:site_public_docs:anonymous-id': 'anon-docs',
    'wayfindr:site_public_docs:visitor-token': 'visitor-token-docs',
    'wayfindr:site_public_docs:support-code': 'WF-DOCS',
  });
  let liveMessage = null;

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    storage,
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    realtime: {
      subscribe: ({ onMessage }) => {
        liveMessage = onMessage;

        return { unsubscribe: () => {} };
      },
    },
    fetch: resumeFetchWithAttachments([]),
  });

  widget.open();
  await settle();

  assert.equal(typeof liveMessage, 'function', 'the widget subscribed to realtime for the active conversation');

  // A live agent message arrives over the socket carrying an image attachment.
  liveMessage({
    conversation: { support_code: 'WF-DOCS', status: 'open' },
    message: {
      id: 20,
      sender: { kind: 'agent', name: 'Ada' },
      type: 'text',
      body: 'Live diagram',
      attachments: [{ id: 200, filename: 'live.png', mime_type: 'image/png', size_bytes: 1024, is_image: true, status: 'ready' }],
      created_at: '2026-07-15T10:05:00.000000Z',
    },
  });
  await settle();

  const img = widget.root.querySelector('.wayfindr-widget__attachment-image');
  assert.ok(img, 'the live message renders its image attachment without waiting for a poll');
  assert.match(img.getAttribute('src'), /\/api\/conversations\/WF-DOCS\/attachments\/200\?/);

  widget.destroy();
});

// --- Composer gating + upload flow ---------------------------------------

function composerFetchMock(calls) {
  return async (url, options) => {
    calls.push({ url, options });

    if (options && options.method === 'DELETE') {
      return jsonResponse(204, {});
    }

    if (url.endsWith('/api/widget/bootstrap')) {
      return jsonResponse(200, {
        data: { site: { public_key: 'site_public_docs', settings: {} }, visitor: { anonymous_id: 'anon-docs', token: 'visitor-token-docs' } },
      });
    }

    if (url.endsWith('/api/conversations')) {
      return jsonResponse(201, { data: { support_code: 'WF-NEW', status: 'open' } });
    }

    if (url.includes('/attachments')) {
      return jsonResponse(201, {
        data: { attachment: { id: 900, filename: 'shot.png', mime_type: 'image/png', size_bytes: 2048, is_image: true, status: 'ready' } },
      });
    }

    if (url.includes('/cobrowse')) {
      return jsonResponse(200, { data: { cobrowse: { state: 'unavailable' } } });
    }

    // POST or GET messages.
    return jsonResponse(201, {
      data: {
        conversation: { support_code: 'WF-NEW', status: 'open' },
        message: { id: 1, sender: { kind: 'visitor' }, type: 'text', body: options && options.body ? (JSON.parse(options.body).body || '') : '', attachments: [], created_at: '2026-07-15T10:00:00.000000Z' },
        messages: [],
      },
    });
  };
}

test('the attach control is gated until a conversation exists, then drives an upload and binds on send', async () => {
  if (typeof globalThis.File !== 'function') {
    return; // requires a File constructor (Node 20+)
  }

  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', { url: 'https://docs.example.test/' });
  const calls = [];
  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-docs',
    storage: memoryStorage(),
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: composerFetchMock(calls),
  });

  widget.open();

  const attachButton = widget.root.querySelector('.wayfindr-widget__attach');
  const fileInput = widget.root.querySelector('.wayfindr-widget__file-input');

  // Gated: no conversation yet, so the attach control is hidden.
  assert.equal(attachButton.hidden, true);

  // Start the conversation with a first text message.
  widget.root.querySelector('.wayfindr-widget__textarea').value = 'My checkout is broken.';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
  await settle();

  assert.equal(attachButton.hidden, false, 'the attach control appears once the conversation is active');

  // Pick a file → it uploads and shows a ready chip.
  const file = new globalThis.File(['imagedata'], 'shot.png', { type: 'image/png' });
  Object.defineProperty(fileInput, 'files', { value: [file], configurable: true });
  fileInput.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  await settle();

  const uploadCall = calls.find((call) => call.url.includes('/attachments'));
  assert.ok(uploadCall, 'selecting a file triggers an upload');

  const chip = widget.root.querySelector('.wayfindr-widget__attach-chip--ready');
  assert.ok(chip, 'a ready chip is shown for the uploaded file');

  // Send again (empty text) — the message must carry the bound attachment id.
  calls.length = 0;
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
  await settle();

  const sendCall = calls.find((call) => call.url.endsWith('/messages') && call.options.method === 'POST');
  assert.ok(sendCall, 'a message send is issued');
  assert.deepEqual(JSON.parse(sendCall.options.body).attachment_ids, [900]);

  // Chips clear after a successful send.
  assert.equal(widget.root.querySelectorAll('.wayfindr-widget__attach-chip').length, 0);

  widget.destroy();
});

test('removing a ready chip deletes the server-side upload', async () => {
  if (typeof globalThis.File !== 'function') {
    return;
  }

  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', { url: 'https://docs.example.test/' });
  const calls = [];
  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-docs',
    storage: memoryStorage(),
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: composerFetchMock(calls),
  });

  widget.open();
  widget.root.querySelector('.wayfindr-widget__textarea').value = 'Start.';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
  await settle();

  const fileInput = widget.root.querySelector('.wayfindr-widget__file-input');
  Object.defineProperty(fileInput, 'files', { value: [new globalThis.File(['x'], 'shot.png', { type: 'image/png' })], configurable: true });
  fileInput.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  await settle();

  // Remove the ready chip.
  widget.root.querySelector('.wayfindr-widget__attach-chip-remove').click();
  await settle();

  const deleteCall = calls.find((call) => call.options && call.options.method === 'DELETE' && call.url.includes('/attachments/900'));
  assert.ok(deleteCall, 'removing a ready chip issues a DELETE for its attachment');
  assert.equal(widget.root.querySelectorAll('.wayfindr-widget__attach-chip').length, 0);

  widget.destroy();
});

test('removing a chip is a no-op while a send is in flight', async () => {
  if (typeof globalThis.File !== 'function') {
    return;
  }

  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', { url: 'https://docs.example.test/' });
  let messagePosts = 0;
  const heldSend = deferred();

  const fetchMock = async (url, options) => {
    if (url.endsWith('/api/widget/bootstrap')) {
      return jsonResponse(200, { data: { site: { public_key: 'site_public_docs', settings: {} }, visitor: { anonymous_id: 'anon-docs', token: 'visitor-token-docs' } } });
    }
    if (url.endsWith('/api/conversations')) {
      return jsonResponse(201, { data: { support_code: 'WF-NEW', status: 'open' } });
    }
    if (url.includes('/attachments')) {
      return jsonResponse(201, { data: { attachment: { id: 900, filename: 'shot.png', mime_type: 'image/png', size_bytes: 2048, is_image: true, status: 'ready' } } });
    }
    if (url.includes('/cobrowse')) {
      return jsonResponse(200, { data: { cobrowse: { state: 'unavailable' } } });
    }
    if (url.endsWith('/messages') && options && options.method === 'POST') {
      messagePosts += 1;
      // Hold the second send (the attachment send) open so composerBusy stays true.
      if (messagePosts === 2) {
        await heldSend.promise;
      }
    }
    return jsonResponse(201, { data: { conversation: { support_code: 'WF-NEW', status: 'open' }, message: { id: messagePosts, sender: { kind: 'visitor' }, type: 'text', body: '', attachments: [], created_at: '2026-07-15T10:00:00.000000Z' }, messages: [] } });
  };

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-docs',
    storage: memoryStorage(),
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: fetchMock,
  });

  widget.open();
  widget.root.querySelector('.wayfindr-widget__textarea').value = 'First message.';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
  await settle();

  const fileInput = widget.root.querySelector('.wayfindr-widget__file-input');
  Object.defineProperty(fileInput, 'files', { value: [new globalThis.File(['x'], 'shot.png', { type: 'image/png' })], configurable: true });
  fileInput.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  await settle();

  // Kick off the attachment send; it hangs, so composerBusy stays true.
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
  await settle();

  const remove = widget.root.querySelector('.wayfindr-widget__attach-chip-remove');
  assert.ok(remove, 'the chip is still shown during the in-flight send');
  assert.equal(remove.disabled, true, 'the remove button is disabled while sending');

  // Even if a click sneaks through, it must not remove the chip.
  remove.click();
  await settle();
  assert.equal(widget.root.querySelectorAll('.wayfindr-widget__attach-chip').length, 1, 'the chip survives a remove attempt during send');

  heldSend.resolve();
  await settle();

  assert.equal(widget.root.querySelectorAll('.wayfindr-widget__attach-chip').length, 0, 'chips clear once the send completes');

  widget.destroy();
});

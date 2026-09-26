const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

// The server takes a message of at most 4000 characters. The composer had no
// limit, the rejection carried no key, and on a FIRST send the conversation is
// created by a request of its own before the message is rejected -- so an
// over-long first message left an empty open conversation that alerted nobody,
// and the visitor a generic "could not be sent" with a Retry that could never
// succeed.

const LIMIT = 4000;

function jsonResponse(status, payload) {
  return { ok: status >= 200 && status < 300, status, json: async () => payload };
}

async function settle() {
  for (let i = 0; i < 4; i++) {
    await new Promise((resolve) => setImmediate(resolve));
  }
}

function memoryStorage(seed) {
  const values = new Map(Object.entries(seed || {}));

  return {
    getItem: (key) => (values.has(key) ? values.get(key) : null),
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
  };
}

// A server that accepts everything, so any refusal the visitor sees came from
// the widget -- and any request it records was one the widget chose to make.
function acceptingFetch(calls, messageAnswer) {
  return async (url, init) => {
    const method = (init && init.method) || 'GET';
    calls.push({ url, method, body: init && init.body ? JSON.parse(init.body) : null });

    if (url.endsWith('/api/widget/bootstrap')) {
      return jsonResponse(200, {
        data: {
          site: { public_key: 'site_public_limit', settings: {} },
          visitor: { anonymous_id: 'anon-limit', token: 'visitor-token-limit' },
        },
      });
    }

    if (url.endsWith('/api/conversations')) {
      return jsonResponse(201, { data: { support_code: 'WF-LIMIT', status: 'open' } });
    }

    if (url.includes('/messages') && method === 'POST') {
      if (messageAnswer) {
        return messageAnswer();
      }

      return jsonResponse(201, {
        data: {
          conversation: { support_code: 'WF-LIMIT', status: 'open' },
          message: { id: 1, sender: { kind: 'visitor' }, type: 'text', body: 'ok', attachments: [], created_at: new Date().toISOString() },
        },
      });
    }

    if (url.includes('/messages')) {
      return jsonResponse(200, { data: { conversation: { support_code: 'WF-LIMIT', status: 'open' }, messages: [] } });
    }

    if (url.includes('/cobrowse')) {
      return jsonResponse(200, { data: { cobrowse: { state: 'unavailable' } } });
    }

    return jsonResponse(200, { data: {} });
  };
}

function messageLimitWidget({ calls, browser, supportCode, messageAnswer } = {}) {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/',
  });

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages: browser ? [browser] : [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_limit',
    storage: memoryStorage(Object.assign({
      'wayfindr:site_public_limit:anonymous-id': 'anon-limit',
    }, supportCode ? {
      'wayfindr:site_public_limit:visitor-token': 'visitor-token-limit',
      'wayfindr:site_public_limit:support-code': supportCode,
    } : {})),
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: 0,
    fetch: acceptingFetch(calls, messageAnswer),
  });

  return { dom, widget };
}

async function messageLimitSend(dom, widget, text) {
  await widget.open();
  await settle();

  widget.root.querySelector('.wayfindr-widget__textarea').value = text;
  widget.root
    .querySelector('.wayfindr-widget__form')
    .dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
  await settle();
}

function messageLimitPosts(calls) {
  return calls.filter((call) => call.method === 'POST' && (call.url.endsWith('/api/conversations') || call.url.includes('/messages')));
}

test('a paste over the limit is kept whole, and the visitor is told at once', async (t) => {
  // A browser enforces `maxlength` by silently cutting a paste to fit: a
  // pasted log reached the agent truncated mid-line and neither side knew.
  // jsdom does not enforce the attribute, so its absence is what is pinned;
  // what it would do was measured in Chromium.
  const { dom, widget } = messageLimitWidget({ calls: [] });
  t.after(() => widget.destroy());

  await widget.open();
  await settle();

  const textarea = widget.root.querySelector('.wayfindr-widget__textarea');
  const status = widget.root.querySelector('.wayfindr-widget__status');
  const paste = 'x'.repeat(LIMIT + 200);

  assert.equal(textarea.hasAttribute('maxlength'), false, 'the composer has a maxlength, which browsers enforce by silently discarding the end of a paste');

  textarea.value = paste;
  textarea.dispatchEvent(new dom.window.Event('input', { bubbles: true }));

  assert.equal(status.textContent, 'A message can be at most 4000 characters long.', 'an over-long draft was not flagged until Send');
  assert.equal(textarea.value, paste, 'the draft was cut, so the part over the limit is lost');

  textarea.value = 'x'.repeat(LIMIT);
  textarea.dispatchEvent(new dom.window.Event('input', { bubbles: true }));

  assert.equal(status.textContent, '', 'the length notice stayed after the draft was shortened to fit');

  // Only its own notice is cleared: other news on the status line stays.
  status.textContent = 'Message sent.';
  textarea.dispatchEvent(new dom.window.Event('input', { bubbles: true }));

  assert.equal(status.textContent, 'Message sent.', 'typing within the limit wiped an unrelated status');
});

test('an over-long first message creates no conversation and says what the limit is', async (t) => {
  const calls = [];
  const { dom, widget } = messageLimitWidget({ calls });
  t.after(() => widget.destroy());

  // Set by script, which `maxlength` does not stop -- a host prefilling the
  // composer, or anything else that writes to it.
  const draft = 'x'.repeat(LIMIT + 1);

  await messageLimitSend(dom, widget, draft);

  assert.deepEqual(
    messageLimitPosts(calls).map((call) => call.url),
    [],
    'an over-long first message still reached the server, which opens an empty conversation before it rejects the message',
  );

  const status = widget.root.querySelector('.wayfindr-widget__status').textContent;

  assert.equal(status, 'A message can be at most 4000 characters long.', 'the visitor was not told the message is too long');
  assert.equal(widget.root.querySelector('.wayfindr-widget__textarea').value, draft, 'the draft was lost, so there is nothing left to shorten');
});

test('the limit is counted in characters, as the server counts them', async (t) => {
  // Four thousand emoji are 8000 UTF-16 code units and 4000 characters. The
  // server's `max:` counts characters and takes this message, so refusing it
  // here would turn away a message that was always going to be accepted.
  const calls = [];
  const { dom, widget } = messageLimitWidget({ calls });
  t.after(() => widget.destroy());

  await messageLimitSend(dom, widget, '😀'.repeat(LIMIT));

  const posts = messageLimitPosts(calls);

  assert.equal(
    posts.length,
    2,
    `four thousand emoji were refused as too long, so the widget is not counting characters: ${widget.root.querySelector('.wayfindr-widget__status').textContent}`,
  );
  // This fake parses with JSON.parse, which accepts half a surrogate pair;
  // PHP's decoder refuses the whole request for one. So the conversation
  // request is checked for what the real server would reject.
  assert.ok(posts[0].body.subject.isWellFormed(), 'the conversation subject ends in half an emoji, which the server refuses outright');
  assert.equal(posts[1].body.body, '😀'.repeat(LIMIT));
});

test('a first message with an emoji at the subject cut still opens its conversation', async () => {
  // The subject is the message cut to the server's 255 characters. Cut by
  // UTF-16 unit, an emoji straddling the cut left a lone surrogate, the server
  // answered "Site not found." -- its decoder had dropped the whole body --
  // and Retry sent the same bytes forever.
  const calls = [];
  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_limit',
    anonymousId: 'anon-limit',
    fetch: acceptingFetch(calls),
  });

  await client.sendFirstMessage('a'.repeat(254) + '😀 thanks');

  const created = calls.find((call) => call.url.endsWith('/api/conversations'));

  assert.equal(
    created.body.subject,
    'a'.repeat(254) + '😀',
    'the subject was not cut to 255 whole characters',
  );
});

test('the server’s own length rejection is said in the widget’s language', async (t) => {
  // The case the pre-check cannot reach: a server whose limit differs from the
  // one this build knows. Its rejection now carries a key, so a German widget
  // says it in German instead of falling back to "could not be sent".
  const calls = [];
  const { dom, widget } = messageLimitWidget({
    calls,
    browser: 'de-DE',
    supportCode: 'WF-LIMIT',
    messageAnswer: () => jsonResponse(422, {
      message: 'A message can be at most 4000 characters long.',
      errors: { body: ['A message can be at most 4000 characters long.'] },
      error_key: 'composer.rejected.too_long',
      error_params: { max: 4000 },
    }),
  });
  t.after(() => widget.destroy());

  await messageLimitSend(dom, widget, 'Hallo');

  assert.equal(
    widget.root.querySelector('.wayfindr-widget__status').textContent,
    'Eine Nachricht darf höchstens 4000 Zeichen lang sein.',
    'the length rejection was not said in the language the panel is speaking',
  );
});

test('sendFirstMessage refuses an over-long body before making any request', async () => {
  const calls = [];
  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_limit',
    anonymousId: 'anon-limit',
    fetch: acceptingFetch(calls),
  });

  let thrown = null;

  try {
    await client.sendFirstMessage('x'.repeat(LIMIT + 1));
  } catch (error) {
    thrown = error;
  }

  assert.deepEqual(
    calls.map((call) => call.url),
    [],
    'sendFirstMessage made requests for a body the server will refuse, and the conversation it opens is left empty',
  );
  assert.ok(thrown, 'an over-long first message was not refused');
  assert.equal(thrown.wayfindrKey, 'composer.rejected.too_long');
  assert.deepEqual(thrown.wayfindrParams, { max: LIMIT });
});

test('sendFirstMessage measures the body the server will measure', async () => {
  // The server trims before it validates, so surrounding whitespace is not
  // part of the length it refuses. Counting it here would refuse a message the
  // server takes.
  const calls = [];
  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_limit',
    anonymousId: 'anon-limit',
    fetch: acceptingFetch(calls),
  });

  let thrown = null;

  try {
    await client.sendFirstMessage('  ' + 'x'.repeat(LIMIT) + '\n');
  } catch (error) {
    thrown = error;
  }

  assert.equal(thrown, null, `a body within the limit once trimmed was refused: ${thrown && thrown.message}`);
  assert.deepEqual(
    calls.map((call) => call.url.replace('http://127.0.0.1:8000', '')),
    ['/api/widget/bootstrap', '/api/conversations', '/api/conversations/WF-LIMIT/messages'],
    'a body within the limit once trimmed was refused',
  );
});

test('sendFirstMessage sends the body it measured', async () => {
  // JavaScript's trim() and the server's are different functions with
  // different whitespace sets. NBSP and U+2028 are whitespace to one and were
  // not to Laravel 10's trim(), so a body measured trimmed but sent as typed
  // could pass here at exactly the limit and be refused after its
  // conversation had been opened -- the empty conversation this guard exists
  // to prevent.
  const calls = [];
  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_limit',
    anonymousId: 'anon-limit',
    fetch: acceptingFetch(calls),
  });

  await client.sendFirstMessage(' ' + 'x'.repeat(LIMIT) + ' ');

  const posted = calls.find((call) => call.url.endsWith('/messages') && call.method === 'POST');

  assert.ok(posted, 'the first message was never sent');
  assert.equal(
    posted.body.body,
    'x'.repeat(LIMIT),
    'sendFirstMessage sent a body other than the one it measured, so the server can refuse what the guard passed',
  );
});

test('sendFirstMessage refuses a body with nothing to send before making any request', async () => {
  // A first message cannot carry a file, so with no text the server refuses
  // it -- but only after the conversation it opens has been created, leaving
  // it empty and alerting nobody. The same for a body that is not a string,
  // which fails the server's `string` rule.
  const cases = [
    ['an empty string', ''],
    ['only whitespace', '  \n\t '],
    ['only a no-break space', ' '],
    ['null', null],
    ['undefined', undefined],
    ['a number', 42],
  ];

  for (const [name, body] of cases) {
    const calls = [];
    const client = Wayfindr.createClient({
      apiBaseUrl: 'http://127.0.0.1:8000',
      sitePublicKey: 'site_public_limit',
      anonymousId: 'anon-limit',
      fetch: acceptingFetch(calls),
    });

    let thrown = null;

    try {
      await client.sendFirstMessage(body);
    } catch (error) {
      thrown = error;
    }

    assert.deepEqual(
      calls.map((call) => call.url),
      [],
      `sendFirstMessage made requests for ${name}, which the server refuses after the conversation it opens is created`,
    );
    assert.ok(thrown, `${name} was not refused`);
    assert.equal(thrown.wayfindrKey, 'composer.rejected.empty', `${name} was refused without the key a caller translates`);
    assert.equal(thrown.message, 'Enter a message or attach a file.');
  }
});

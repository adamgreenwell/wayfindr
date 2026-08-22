const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

// The composer has never been gated before. It is gated only until the site's
// questions are answered, and never once a conversation exists.

function jsonResponse(status, payload) {
  return { ok: status >= 200 && status < 300, status, json: async () => payload };
}

function memoryStorage(seed) {
  const values = new Map(Object.entries(seed || {}));

  return {
    getItem: (key) => (values.has(key) ? values.get(key) : null),
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
  };
}

async function settle() {
  for (let i = 0; i < 4; i += 1) {
    await new Promise((resolve) => setImmediate(resolve));
  }
}

function widgetWith({ intake, identified = false, storedSupportCode = null } = {}) {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });

  const seed = {
    'wayfindr:site_public_docs:anonymous-id': 'anon-docs',
    'wayfindr:site_public_docs:visitor-token': 'visitor-token-docs',
  };

  if (storedSupportCode) {
    seed['wayfindr:site_public_docs:support-code'] = storedSupportCode;
  }

  const sent = [];

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    storage: memoryStorage(seed),
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: async (url, init) => {
      if (url.includes('/cobrowse')) {
        return jsonResponse(200, { data: { cobrowse: { state: 'unavailable' } } });
      }

      sent.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.endsWith('/bootstrap')) {
        return jsonResponse(200, {
          data: {
            site: {
              public_key: 'site_public_docs',
              settings: {},
              color: 'blue',
              availability: { away: false, message: null, opens_at: null, timezone: 'UTC' },
              intake,
            },
            visitor: { anonymous_id: 'anon-docs', token: 'visitor-token-docs', identified },
          },
        });
      }

      if (url.endsWith('/api/conversations')) {
        return jsonResponse(201, { data: { conversation: { support_code: 'WF-DOCS', status: 'open' } } });
      }

      if (url.includes('/messages')) {
        return jsonResponse(200, {
          data: { conversation: { support_code: 'WF-DOCS', status: 'open' }, messages: [], message: { id: 1 } },
        });
      }

      return jsonResponse(200, { data: {} });
    },
  });

  return { widget, dom, sent };
}

const ASKS_BOTH = {
  asks: true,
  intro: 'Tell us who you are.',
  fields: { name: 'required', email: 'required', reason: 'off' },
};

test('a site that asks nothing shows no form and no gate', async () => {
  const { widget } = widgetWith({ intake: { asks: false, intro: null, fields: { name: 'off', email: 'off', reason: 'off' } } });

  widget.root.querySelector('.wayfindr-widget__launcher').click();
  await settle();

  assert.equal(widget.root.querySelector('.wayfindr-widget__intake').hidden, true);
  assert.equal(widget.root.querySelector('.wayfindr-widget__form').hidden, false);
});

test('the composer is held back until the questions are answered', async () => {
  const { widget, dom } = widgetWith({ intake: ASKS_BOTH });

  widget.root.querySelector('.wayfindr-widget__launcher').click();
  await settle();

  const intake = widget.root.querySelector('.wayfindr-widget__intake');

  assert.equal(intake.hidden, false);
  assert.equal(widget.root.querySelector('.wayfindr-widget__form').hidden, true);
  assert.ok(widget.root.querySelector('.wayfindr-widget__intake-intro').textContent.includes('Tell us who you are'));

  intake.querySelector('[name="name"]').value = 'Avery Lane';
  intake.querySelector('[name="email"]').value = 'avery@example.test';
  intake.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
  await settle();

  assert.equal(intake.hidden, true);
  assert.equal(widget.root.querySelector('.wayfindr-widget__form').hidden, false);
});

test('a required field left blank keeps the composer closed', async () => {
  const { widget, dom } = widgetWith({ intake: ASKS_BOTH });

  widget.root.querySelector('.wayfindr-widget__launcher').click();
  await settle();

  const intake = widget.root.querySelector('.wayfindr-widget__intake');
  intake.querySelector('[name="name"]').value = 'Avery Lane';
  intake.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
  await settle();

  assert.equal(intake.hidden, false, 'the form stays until the answer is there');
  assert.equal(widget.root.querySelector('.wayfindr-widget__intake-error').hidden, false);
  assert.equal(widget.root.querySelector('.wayfindr-widget__form').hidden, true);
});

test('the answers travel with the conversation that follows', async () => {
  const { widget, dom, sent } = widgetWith({ intake: ASKS_BOTH });

  widget.root.querySelector('.wayfindr-widget__launcher').click();
  await settle();

  const intake = widget.root.querySelector('.wayfindr-widget__intake');
  intake.querySelector('[name="name"]').value = 'Avery Lane';
  intake.querySelector('[name="email"]').value = 'avery@example.test';
  intake.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
  await settle();

  widget.root.querySelector('.wayfindr-widget__textarea').value = 'Checkout is broken.';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true })
  );
  await settle();

  const start = sent.find((call) => call.url.endsWith('/api/conversations'));

  assert.ok(start, 'a conversation was started');
  assert.equal(start.body.visitor_name, 'Avery Lane');
  assert.equal(start.body.visitor_email, 'avery@example.test');
  // A field the site does not ask for must not be sent at all: the server
  // refuses it.
  assert.ok(!('visitor_reason' in start.body));
});

test('a visitor the host app already identified is not asked again', async () => {
  // Asking a signed-in customer for their email is the fastest way to make a
  // widget feel unfinished.
  //
  // The exemption is the SERVER's: it sends the fields it will enforce, already
  // accounting for identification. The widget draws what it is told, so it
  // cannot hide a form for a field the server still demands -- which is exactly
  // how identified visitors were handed a 422 they could do nothing about.
  const { widget } = widgetWith({
    intake: { asks: false, intro: 'Tell us who you are.', fields: { name: 'off', email: 'off', reason: 'off' } },
    identified: true,
  });

  widget.root.querySelector('.wayfindr-widget__launcher').click();
  await settle();

  assert.equal(widget.root.querySelector('.wayfindr-widget__intake').hidden, true);
  assert.equal(widget.root.querySelector('.wayfindr-widget__form').hidden, false);
});

test('an identified visitor is still asked for an email out of hours', async () => {
  // Identification does not make somebody reachable: an external ID is
  // deliberately not an email, so a known visitor at 3am is still a visitor
  // nobody can reply to.
  const { widget } = widgetWith({
    intake: { asks: true, intro: null, fields: { name: 'off', email: 'required', reason: 'off' } },
    identified: true,
  });

  widget.root.querySelector('.wayfindr-widget__launcher').click();
  await settle();

  const intake = widget.root.querySelector('.wayfindr-widget__intake');

  assert.equal(intake.hidden, false);
  assert.ok(intake.querySelector('[name="email"]'));
  assert.equal(intake.querySelector('[name="name"]'), null);
});

test('returning to an existing conversation never meets the form', async () => {
  const { widget } = widgetWith({ intake: ASKS_BOTH, storedSupportCode: 'WF-DOCS' });

  widget.root.querySelector('.wayfindr-widget__launcher').click();
  await settle();

  assert.equal(widget.root.querySelector('.wayfindr-widget__intake').hidden, true);
  assert.equal(widget.root.querySelector('.wayfindr-widget__form').hidden, false);
});

test('the form is built as elements, so configured labels cannot inject markup', async () => {
  const { widget } = widgetWith({
    intake: {
      asks: true,
      intro: '<img src=x onerror="window.__owned = true">',
      fields: { name: 'required', email: 'off', reason: 'off' },
    },
  });

  widget.root.querySelector('.wayfindr-widget__launcher').click();
  await settle();

  const intro = widget.root.querySelector('.wayfindr-widget__intake-intro');

  assert.equal(intro.querySelector('img'), null);
  assert.ok(intro.textContent.includes('<img'));
});

test('crossing into out-of-hours re-asks for the newly required email', async () => {
  // Answering covers the questions that were asked. A visitor who answered
  // while the desk was open and sent after it closed hit a 422 for an email
  // they were never shown a field for -- and reopening could not clear it,
  // because the answered flag never reset.
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });

  let fields = { name: 'required', email: 'off', reason: 'off' };

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    storage: memoryStorage({
      'wayfindr:site_public_docs:anonymous-id': 'anon-docs',
      'wayfindr:site_public_docs:visitor-token': 'visitor-token-docs',
    }),
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: async (url) => {
      if (url.includes('/cobrowse')) {
        return jsonResponse(200, { data: { cobrowse: { state: 'unavailable' } } });
      }

      if (url.endsWith('/bootstrap')) {
        return jsonResponse(200, {
          data: {
            site: {
              public_key: 'site_public_docs',
              settings: {},
              color: 'blue',
              availability: { away: false, message: null, opens_at: null, timezone: 'UTC' },
              intake: { asks: true, intro: null, fields },
            },
            visitor: { anonymous_id: 'anon-docs', token: 'visitor-token-docs' },
          },
        });
      }

      return jsonResponse(200, { data: {} });
    },
  });

  const launcher = widget.root.querySelector('.wayfindr-widget__launcher');
  const intake = widget.root.querySelector('.wayfindr-widget__intake');

  launcher.click();
  await settle();

  intake.querySelector('[name="name"]').value = 'Avery Lane';
  intake.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
  await settle();

  assert.equal(intake.hidden, true, 'answered, so the composer is available');

  // Closing time passes. The name is now known, but an email is required.
  fields = { name: 'off', email: 'required', reason: 'off' };
  widget.root.querySelector('.wayfindr-widget__close').click();
  launcher.click();
  await settle();

  assert.equal(intake.hidden, false, 'a newly required question must be asked');
  assert.ok(intake.querySelector('[name="email"]'));
});

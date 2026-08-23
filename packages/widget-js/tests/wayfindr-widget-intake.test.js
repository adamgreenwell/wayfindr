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

// A promise somebody else decides when to settle, so a test can hold a
// response open and act in the window it leaves.
function deferred() {
  let release;
  const promise = new Promise((resolve) => { release = resolve; });

  return { promise, release };
}

function widgetWith({
  intake,
  identified = false,
  storedSupportCode = null,
  // The rules the server serves from the SECOND bootstrap onward, so a test
  // can move the ground under a panel that is already open.
  intakeAfter = null,
  holdBootstrap = null,
  holdResume = null,
  resumeStatus = 200,
} = {}) {
  let bootstraps = 0;
  let resumes = 0;
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
        bootstraps += 1;

        if (holdBootstrap) {
          await holdBootstrap;
        }

        return jsonResponse(200, {
          data: {
            site: {
              public_key: 'site_public_docs',
              settings: {},
              color: 'blue',
              availability: { away: false, message: null, opens_at: null, timezone: 'UTC' },
              intake: (intakeAfter && bootstraps > 1) ? intakeAfter : intake,
            },
            visitor: { anonymous_id: 'anon-docs', token: 'visitor-token-docs', identified },
          },
        });
      }

      if (url.endsWith('/api/conversations')) {
        return jsonResponse(201, { data: { conversation: { support_code: 'WF-DOCS', status: 'open' } } });
      }

      if (url.includes('/messages')) {
        resumes += 1;

        // Only the resume -- the first /messages read -- is held or rejected;
        // the send that follows must still behave normally.
        if (resumes === 1) {
          if (holdResume) {
            await holdResume;
          }

          if (resumeStatus !== 200) {
            return jsonResponse(resumeStatus, { message: 'Unknown conversation.' });
          }
        }

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

test('a rejected answer can be corrected rather than trapping the visitor', async () => {
  // Browsers accept "a@b" as an email input and the server does not, so a 422
  // here is ordinary. Hiding the form that could fix it, keeping the answered
  // flag, and offering a Retry that resends the same values meant the visitor
  // could not start a conversation at all.
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });

  let rejectIntake = true;
  const started = [];

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
    fetch: async (url, init) => {
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
              intake: { asks: true, intro: null, fields: { name: 'off', email: 'required', reason: 'off' } },
            },
            visitor: { anonymous_id: 'anon-docs', token: 'visitor-token-docs' },
          },
        });
      }

      if (url.endsWith('/api/conversations')) {
        started.push(JSON.parse(init.body));

        if (rejectIntake) {
          return jsonResponse(422, { message: 'The visitor email field must be a valid email address.' });
        }

        return jsonResponse(201, { data: { conversation: { support_code: 'WF-DOCS', status: 'open' } } });
      }

      return jsonResponse(200, { data: {} });
    },
  });

  const launcher = widget.root.querySelector('.wayfindr-widget__launcher');
  const intake = widget.root.querySelector('.wayfindr-widget__intake');

  launcher.click();
  await settle();

  intake.querySelector('[name="email"]').value = 'a@b';
  intake.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
  await settle();

  widget.root.querySelector('.wayfindr-widget__textarea').value = 'Checkout is broken.';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true })
  );
  await settle();

  // The form is back, carrying the server's reason.
  assert.equal(intake.hidden, false, 'the visitor must be able to correct the answer');
  assert.equal(widget.root.querySelector('.wayfindr-widget__intake-error').hidden, false);
  assert.ok(widget.root.querySelector('.wayfindr-widget__intake-error').textContent.includes('valid email'));

  // And correcting it works.
  rejectIntake = false;
  intake.querySelector('[name="email"]').value = 'avery@example.test';
  intake.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
  await settle();

  widget.root.querySelector('.wayfindr-widget__textarea').value = 'Checkout is broken.';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true })
  );
  await settle();

  assert.equal(started.length, 2);
  assert.equal(started[1].visitor_email, 'avery@example.test');
});

test('intake inputs carry the server length limit', async () => {
  const { widget } = widgetWith({ intake: ASKS_BOTH });

  widget.root.querySelector('.wayfindr-widget__launcher').click();
  await settle();

  assert.equal(widget.root.querySelector('[name="email"]').maxLength, 255);
});

// The races below all have one shape: the gate is decided from state that is
// still provisional -- a resume that has not set the support code, an answer
// that has not landed, or rules that have gone stale. Each one let a visitor
// reach a conversation the site meant to ask about first.

test('a visitor whose conversation is still restoring is never shown the form', async () => {
  const resume = deferred();
  const { widget } = widgetWith({
    intake: ASKS_BOTH,
    storedSupportCode: 'WF-DOCS',
    holdResume: resume.promise,
  });

  await settle();

  // The support code is not set until the resume lands, but this visitor
  // plainly has a conversation already.
  assert.equal(
    widget.root.querySelector('.wayfindr-widget__intake').hidden,
    true,
    'no form appears in the gap before the resume lands'
  );
  assert.equal(widget.root.querySelector('.wayfindr-widget__form').hidden, false);

  resume.release();
  await settle();

  assert.equal(widget.root.querySelector('.wayfindr-widget__intake').hidden, true);
  assert.equal(widget.root.querySelector('.wayfindr-widget__form').hidden, false);
});

test('a stored code the server rejects still leads to the questions', async () => {
  // The other half of the one above: suppressing intake while a resume is in
  // flight must not suppress it for a visitor whose code turns out to be dead.
  const { widget } = widgetWith({
    intake: ASKS_BOTH,
    storedSupportCode: 'WF-GONE',
    resumeStatus: 404,
  });

  await settle();

  assert.equal(
    widget.root.querySelector('.wayfindr-widget__intake').hidden,
    false,
    'a visitor with no usable conversation really is starting fresh, and is asked'
  );
  assert.equal(widget.root.querySelector('.wayfindr-widget__form').hidden, true);
});

test('a message sent before the first answer lands waits for the questions', async () => {
  // The composer is visible on open and bootstrap has not answered yet, so
  // there is a window in which a fast visitor can submit through a gate that
  // is about to close.
  const boot = deferred();
  const { widget, dom, sent } = widgetWith({ intake: ASKS_BOTH, holdBootstrap: boot.promise });

  widget.root.querySelector('.wayfindr-widget__launcher').click();
  await settle();

  widget.root.querySelector('.wayfindr-widget__textarea').value = 'Is anybody there?';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true })
  );
  await settle();

  boot.release();
  await settle();

  assert.ok(
    !sent.some((call) => call.url.endsWith('/api/conversations')),
    'the send stops rather than posting empty answers to questions never shown'
  );
  assert.equal(widget.root.querySelector('.wayfindr-widget__intake').hidden, false);
  assert.equal(
    widget.root.querySelector('.wayfindr-widget__textarea').value,
    'Is anybody there?',
    'the message survives the wait'
  );
});

const ASKS_NOTHING = { asks: false, intro: null, fields: { name: 'off', email: 'off', reason: 'off' } };
const ASKS_EMAIL = { asks: true, intro: 'One thing first.', fields: { name: 'off', email: 'required', reason: 'off' } };

test('questions that changed while the panel sat open are asked before a conversation exists', async () => {
  // The desk closes, or an operator edits what the site asks. The panel was
  // opened before that and still holds the old answer.
  const { widget, dom, sent } = widgetWith({ intake: ASKS_NOTHING, intakeAfter: ASKS_EMAIL });

  widget.root.querySelector('.wayfindr-widget__launcher').click();
  await settle();

  // Nothing was asked when this panel opened, so the composer is open.
  assert.equal(widget.root.querySelector('.wayfindr-widget__form').hidden, false);

  widget.root.querySelector('.wayfindr-widget__textarea').value = 'My order never arrived.';
  widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new dom.window.Event('submit', { bubbles: true, cancelable: true })
  );
  await settle();

  const intake = widget.root.querySelector('.wayfindr-widget__intake');

  assert.ok(
    !sent.some((call) => call.url.endsWith('/api/conversations')),
    'no conversation is created against rules the server has already moved past'
  );
  assert.equal(intake.hidden, false, 'the newly required question is asked');
  assert.ok(intake.querySelector('[name="email"]'), 'and the form is rebuilt from the NEW rules, not the stale ones');
  assert.equal(widget.root.querySelector('.wayfindr-widget__form').hidden, true);
  assert.equal(
    widget.root.querySelector('.wayfindr-widget__textarea').value,
    'My order never arrived.',
    'the message the visitor already typed is kept'
  );
});

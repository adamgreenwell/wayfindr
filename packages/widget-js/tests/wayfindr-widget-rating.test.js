const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

// Every other number the reports page shows says how FAST the desk moved. This
// prompt is the only thing in the product that asks whether that helped, so the
// question it asks has to survive a locale, an operator's own wording, and a
// visitor who answers twice.

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

function widgetWith({
  rating = { asks: true, intro: null },
  status = 'closed',
  locale = null,
  ratingStatus = 201,
} = {}) {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });

  const sent = [];

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    locale,
    storage: memoryStorage({
      'wayfindr:site_public_docs:anonymous-id': 'anon-docs',
      'wayfindr:site_public_docs:visitor-token': 'visitor-token-docs',
      'wayfindr:site_public_docs:support-code': 'WF-DOCS',
    }),
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: async (url, init) => {
      if (url.includes('/cobrowse')) {
        return jsonResponse(200, { data: { cobrowse: { state: 'unavailable' } } });
      }

      sent.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      // Declared before the catch-all on purpose: a new endpoint that falls
      // through to `{ data: {} }` looks like a successful no-op, and the test
      // then passes while the widget silently does nothing.
      if (url.includes('/rating')) {
        return ratingStatus === 201
          ? jsonResponse(201, { data: { rating: { score: 'good' } } })
          : jsonResponse(ratingStatus, { message: 'No.' });
      }

      if (url.endsWith('/bootstrap')) {
        return jsonResponse(200, {
          data: {
            site: {
              public_key: 'site_public_docs',
              settings: {},
              color: 'blue',
              availability: { away: false, message: null, opens_at: null, timezone: 'UTC' },
              intake: { asks: false, intro: null, fields: { name: 'off', email: 'off', reason: 'off' } },
              rating,
            },
            visitor: { anonymous_id: 'anon-docs', token: 'visitor-token-docs', identified: false },
          },
        });
      }

      if (url.includes('/messages')) {
        return jsonResponse(200, {
          data: { conversation: { support_code: 'WF-DOCS', status }, messages: [], message: { id: 1 } },
        });
      }

      return jsonResponse(200, { data: {} });
    },
  });

  return { widget, dom, sent };
}

async function openPanel(widget) {
  widget.root.querySelector('.wayfindr-widget__launcher').click();
  await settle();
}

function prompt(widget) {
  return widget.root.querySelector('.wayfindr-widget__rating');
}

function score(widget, name) {
  return widget.root.querySelector('.wayfindr-widget__rating-score[data-score="' + name + '"]');
}

test('a site that does not ask shows no prompt, however the conversation ended', async () => {
  const { widget } = widgetWith({ rating: { asks: false, intro: null } });
  await openPanel(widget);

  assert.equal(prompt(widget).hidden, true);
});

test('an open conversation is not asked about', async () => {
  // Asking mid-conversation collects an answer about work that is not finished,
  // and interrupts the visitor while they are still waiting for it.
  const { widget } = widgetWith({ status: 'open' });
  await openPanel(widget);

  assert.equal(prompt(widget).hidden, true);
});

test('a closed conversation on an asking site is asked how it went', async () => {
  const { widget } = widgetWith();
  await openPanel(widget);

  assert.equal(prompt(widget).hidden, false);
  assert.equal(widget.root.querySelector('.wayfindr-widget__rating-intro').textContent, 'How did that go?');
});

test('the send button is dead until an answer is picked', async () => {
  // Otherwise submitting silently does nothing, which reads as a broken widget
  // rather than as a question still waiting for an answer.
  const { widget } = widgetWith();
  await openPanel(widget);

  const send = widget.root.querySelector('.wayfindr-widget__rating-send');

  assert.equal(send.disabled, true);

  score(widget, 'good').click();

  assert.equal(send.disabled, false);
  assert.equal(score(widget, 'good').getAttribute('aria-pressed'), 'true');
  assert.equal(score(widget, 'bad').getAttribute('aria-pressed'), 'false');
});

test('answering sends the score and the comment, and the question goes away', async () => {
  const { widget, sent } = widgetWith();
  await openPanel(widget);

  score(widget, 'bad').click();
  widget.root.querySelector('.wayfindr-widget__rating-comment').value = '  Nobody read my question.  ';
  widget.root.querySelector('.wayfindr-widget__rating').dispatchEvent(new widget.root.ownerDocument.defaultView.Event('submit', { cancelable: true }));
  await settle();

  const posted = sent.find((entry) => entry.url.includes('/rating'));

  assert.ok(posted, 'the rating was never sent');
  assert.equal(posted.body.score, 'bad');
  assert.equal(posted.body.comment, 'Nobody read my question.');
  assert.equal(posted.body.visitor_token, 'visitor-token-docs');
  assert.equal(prompt(widget).hidden, true);
});

test('a visitor who has answered is not asked again', async () => {
  // A widget that keeps asking is one they stop reading -- and the answer they
  // already gave is the one that counts.
  const { widget } = widgetWith();
  await openPanel(widget);

  score(widget, 'good').click();
  widget.root.querySelector('.wayfindr-widget__rating').dispatchEvent(new widget.root.ownerDocument.defaultView.Event('submit', { cancelable: true }));
  await settle();

  // The first version of this test closed and reopened the panel, which never
  // re-runs the prompt at all -- so it passed against a widget that would have
  // asked again. Refresh genuinely re-reads the conversation and re-renders,
  // and the server still says closed.
  widget.root.querySelector('.wayfindr-widget__refresh').click();
  await settle();

  assert.equal(prompt(widget).hidden, true);
});

test('a refused rating says so and leaves the question open', async () => {
  // Losing the answer silently is worse than not asking: the visitor believes
  // they have been heard.
  const { widget } = widgetWith({ ratingStatus: 422 });
  await openPanel(widget);

  score(widget, 'good').click();
  widget.root.querySelector('.wayfindr-widget__rating').dispatchEvent(new widget.root.ownerDocument.defaultView.Event('submit', { cancelable: true }));
  await settle();

  const statusEl = widget.root.querySelector('.wayfindr-widget__rating-status');

  assert.equal(statusEl.hidden, false);
  assert.equal(statusEl.textContent, 'That could not be sent.');
  assert.equal(prompt(widget).hidden, false, 'the visitor must still be able to try again');
});

test('the question is asked in the visitor language', async () => {
  const { widget } = widgetWith({ locale: 'de' });
  await openPanel(widget);

  assert.equal(widget.root.querySelector('.wayfindr-widget__rating-intro').textContent, 'Wie ist es gelaufen?');
  assert.equal(score(widget, 'bad').textContent, 'Schlecht');
  assert.equal(widget.root.querySelector('.wayfindr-widget__rating-send').textContent, 'Senden');
});

test('an operator wording outranks the catalogue', async () => {
  const { widget } = widgetWith({ locale: 'de', rating: { asks: true, intro: 'War alles gut?' } });
  await openPanel(widget);

  assert.equal(widget.root.querySelector('.wayfindr-widget__rating-intro').textContent, 'War alles gut?');
});

test('operator wording is written as text, never as markup', async () => {
  // Site settings are operator-authored, but the widget renders on somebody
  // else's page and never builds HTML from a server string.
  const { widget } = widgetWith({ rating: { asks: true, intro: '<img src=x onerror="alert(1)">' } });
  await openPanel(widget);

  const intro = widget.root.querySelector('.wayfindr-widget__rating-intro');

  assert.equal(intro.querySelector('img'), null);
  assert.equal(intro.textContent, '<img src=x onerror="alert(1)">');
});

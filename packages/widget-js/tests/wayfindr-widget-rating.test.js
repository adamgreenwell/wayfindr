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
  // What the SERVER says about the close currently on the table: has it been
  // answered already (or is there no ratable close at all). The widget must not
  // decide this for itself.
  rated = false,
  // A real server reports the close as rated once it has been answered. Set
  // this false to model the other case: the conversation was reopened and
  // closed again, so the close now on the table is a NEW one and unanswered.
  ratedAfterAnswer = true,
  // Which close the server says is current. Changing it after an answer is how
  // a reopen-and-close is modelled.
  episodeAfterAnswer = 'episode-2',
} = {}) {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });

  const sent = [];
  let answered = false;
  let episode = 'episode-1';

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
        if (ratingStatus === 201) {
          answered = true;
        }

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
          data: {
            conversation: {
              support_code: 'WF-DOCS',
              status,
              awaiting_rating: answered ? ! ratedAfterAnswer : ! rated,
              rating_episode: answered ? episodeAfterAnswer : episode,
            },
            messages: [],
            message: { id: 1 },
          },
        });
      }

      return jsonResponse(200, { data: {} });
    },
  });

  widget.mockEpisode = (next) => { episode = next; };

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
  // already gave is the one that counts. The server reports this close as
  // answered from here on, which is what a reload would also see.
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

test('a close the server says is already rated is not asked about again', async () => {
  // The reload case. Widget memory is gone, so without the server saying so the
  // visitor is asked a second time about a close they already answered.
  const { widget } = widgetWith({ rated: true });
  await openPanel(widget);

  assert.equal(prompt(widget).hidden, true);
});

test('a close the server says is unrated is asked about, whatever happened before', async () => {
  // The genuine-reopen case, from the other side: after answering, a reopen and
  // a new close is a new question, and only the server knows that happened.
  const { widget } = widgetWith({ ratedAfterAnswer: false });
  await openPanel(widget);

  score(widget, 'good').click();
  widget.root.querySelector('.wayfindr-widget__rating').dispatchEvent(new widget.root.ownerDocument.defaultView.Event('submit', { cancelable: true }));
  await settle();

  assert.equal(prompt(widget).hidden, true);

  // Refresh re-reads, and the server reports a NEW close, unanswered.
  widget.root.querySelector('.wayfindr-widget__refresh').click();
  await settle();

  assert.equal(prompt(widget).hidden, false);
});

test('a new close arrives with an empty form, not the previous answer', async () => {
  // Rate one close, get reopened and closed again. The prompt reappears
  // because the server says so -- and it must not reappear with the old score
  // still selected and the old comment still typed, because the send button
  // would already be enabled and one tap would copy an answer about different
  // work into this close.
  const { widget, sent } = widgetWith({ ratedAfterAnswer: false });
  await openPanel(widget);

  score(widget, 'bad').click();
  widget.root.querySelector('.wayfindr-widget__rating-comment').value = 'About the FIRST close.';
  widget.root.querySelector('.wayfindr-widget__rating').dispatchEvent(new widget.root.ownerDocument.defaultView.Event('submit', { cancelable: true }));
  await settle();

  widget.root.querySelector('.wayfindr-widget__refresh').click();
  await settle();

  assert.equal(prompt(widget).hidden, false, 'the new close should be asked about');
  assert.equal(widget.root.querySelector('.wayfindr-widget__rating-comment').value, '');
  assert.equal(score(widget, 'bad').getAttribute('aria-pressed'), 'false');
  assert.equal(widget.root.querySelector('.wayfindr-widget__rating-send').disabled, true);

  // And submitting without choosing sends nothing at all.
  widget.root.querySelector('.wayfindr-widget__rating').dispatchEvent(new widget.root.ownerDocument.defaultView.Event('submit', { cancelable: true }));
  await settle();

  assert.equal(sent.filter((entry) => entry.url.includes('/rating')).length, 1);
});

test('a conversation with no ratable close is not asked about', async () => {
  // On an upgraded install a conversation closed before lifecycle recording has
  // no recorded close, so there is nothing the endpoint would accept. Showing
  // the prompt and then refusing the answer is worse than never asking.
  const { widget } = widgetWith({ rated: true });
  await openPanel(widget);

  assert.equal(prompt(widget).hidden, true);
});

test('an unsubmitted draft does not survive into the next close', async () => {
  // The gap the boolean alone could not close: nothing was ever submitted, so
  // `awaiting_rating` is true across BOTH episodes and the answered-to-
  // unanswered transition never fires. The draft reappears against different
  // work, already ready to send.
  const { widget } = widgetWith();
  await openPanel(widget);

  score(widget, 'bad').click();
  widget.root.querySelector('.wayfindr-widget__rating-comment').value = 'Typed about the FIRST close.';

  // No submit. The conversation is reopened and closed again, so the server
  // reports a different close, still awaiting an answer.
  widget.mockEpisode('episode-9');
  widget.root.querySelector('.wayfindr-widget__refresh').click();
  await settle();

  assert.equal(prompt(widget).hidden, false);
  assert.equal(widget.root.querySelector('.wayfindr-widget__rating-comment').value, '');
  assert.equal(score(widget, 'bad').getAttribute('aria-pressed'), 'false');
  assert.equal(widget.root.querySelector('.wayfindr-widget__rating-send').disabled, true);
});

test('the same close does not clear a draft the visitor is still typing', async () => {
  // The other direction: a poll or a refresh while the visitor is mid-answer
  // must not wipe what they have entered.
  const { widget } = widgetWith();
  await openPanel(widget);

  score(widget, 'ok').click();
  widget.root.querySelector('.wayfindr-widget__rating-comment').value = 'Half a thought';

  widget.root.querySelector('.wayfindr-widget__refresh').click();
  await settle();

  assert.equal(widget.root.querySelector('.wayfindr-widget__rating-comment').value, 'Half a thought');
  assert.equal(score(widget, 'ok').getAttribute('aria-pressed'), 'true');
});

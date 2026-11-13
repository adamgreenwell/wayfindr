const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

// A site's own published template (#1157): the widget asks the site's wording,
// in the visitor's language with English as the fallback, on the template's own
// scale, and sends back the version it showed.

function jsonResponse(status, payload) {
  return { ok: status >= 200 && status < 300, status, json: async () => payload };
}

async function settle() {
  for (let i = 0; i < 4; i += 1) {
    await new Promise((resolve) => setImmediate(resolve));
  }
}

const THREE = {
  scale: 'three',
  scores: ['good', 'ok', 'bad'],
  overall: { en: 'Did we sort it out?', it: 'Abbiamo risolto?' },
  agent: { en: 'And {name} — how did they do?', de: 'Und {name} — wie war es?' },
  comment: null,
  labels: {
    good: { en: 'Sorted' }, ok: { en: 'Partly' }, bad: { en: 'Not really', de: 'Eher nicht' },
    not_applicable: { en: 'Not my case' },
  },
};

const FIVE = {
  scale: 'five',
  scores: ['1', '2', '3', '4', '5'],
  overall: { en: 'Rate us out of five' },
  agent: null,
  comment: { en: 'Tell us more' },
  labels: { 1: { en: '1★' }, 2: { en: '2★' }, 3: { en: '3★' }, 4: { en: '4★' }, 5: { en: '5★' } },
};

function widgetWith({ definition = THREE, version = 3, agent = { ref: 'ref-ada', name: 'Ada' }, locale = null, languages = ['en-US'] } = {}) {
  let shown = { definition, version };
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', { url: 'https://docs.example.test/' });
  const sent = [];
  const values = new Map(Object.entries({
    'wayfindr:site_public_docs:anonymous-id': 'anon-docs',
    'wayfindr:site_public_docs:visitor-token': 'visitor-token-docs',
    'wayfindr:site_public_docs:support-code': 'WF-DOCS',
  }));

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages, language: languages[0] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    locale,
    storage: { getItem: (k) => (values.has(k) ? values.get(k) : null), setItem: (k, v) => values.set(k, v), removeItem: (k) => values.delete(k) },
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: async (url, init) => {
      if (url.includes('/cobrowse')) {
        return jsonResponse(200, { data: { cobrowse: { state: 'unavailable' } } });
      }

      sent.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.includes('/rating')) {
        return jsonResponse(201, { data: { rating: { score: 'x' } } });
      }

      if (url.endsWith('/bootstrap')) {
        return jsonResponse(200, { data: {
          site: {
            public_key: 'site_public_docs', settings: {}, color: 'blue',
            availability: { away: false, message: null, opens_at: null, timezone: 'UTC' },
            intake: { asks: false, intro: null, fields: { name: 'off', email: 'off', reason: 'off' } },
            rating: { asks: true, intro: 'Operator intro is for the built-ins', template: { key: 'custom', version: shown.version, definition: shown.definition } },
          },
          visitor: { anonymous_id: 'anon-docs', token: 'visitor-token-docs', identified: false },
        } });
      }

      if (url.includes('/messages')) {
        return jsonResponse(200, { data: {
          conversation: { support_code: 'WF-DOCS', status: 'closed', awaiting_rating: true, rating_episode: 'episode-1', rating_agent: agent },
          messages: [], message: { id: 1 },
        } });
      }

      return jsonResponse(200, { data: {} });
    },
  });

  widget.publish = (next, nextVersion) => { shown = { definition: next, version: nextVersion }; };

  return { widget, sent };
}

async function openPanel(widget) {
  widget.root.querySelector('.wayfindr-widget__launcher').click();
  await settle();
}

const q = (widget, selector) => widget.root.querySelector(selector);
const qa = (widget, selector) => [...widget.root.querySelectorAll(selector)];

async function submit(widget) {
  q(widget, '.wayfindr-widget__rating').dispatchEvent(new widget.root.ownerDocument.defaultView.Event('submit', { cancelable: true }));
  await settle();
}

test('asks the site’s own wording and labels, and sends the version it showed', async () => {
  const { widget, sent } = widgetWith();
  await openPanel(widget);

  assert.equal(q(widget, '.wayfindr-widget__rating-intro').textContent, 'Did we sort it out?');
  assert.deepEqual(qa(widget, '.wayfindr-widget__rating-score').map((b) => b.textContent), ['Sorted', 'Partly', 'Not really']);
  assert.equal(q(widget, '.wayfindr-widget__rating-agent-question').textContent, 'And Ada — how did they do?');
  assert.equal(q(widget, '.wayfindr-widget__rating-agent-score[data-agent-score="not_applicable"]').textContent, 'Not my case');

  q(widget, '.wayfindr-widget__rating-score[data-score="ok"]').click();
  q(widget, '.wayfindr-widget__rating-agent-score[data-agent-score="good"]').click();
  await submit(widget);

  const post = sent.find((r) => r.url.includes('/rating'));
  assert.equal(post.body.template, 'custom');
  assert.equal(post.body.template_version, 3);
  assert.equal(post.body.score, 'ok');
  assert.equal(post.body.agent_score, 'good');
});

test('a five-star template offers five answers, and those are what is sent', async () => {
  const { widget, sent } = widgetWith({ definition: FIVE, agent: null });
  await openPanel(widget);

  assert.deepEqual(qa(widget, '.wayfindr-widget__rating-score').map((b) => b.getAttribute('data-score')), ['1', '2', '3', '4', '5']);
  assert.equal(q(widget, '.wayfindr-widget__rating-agent').hidden, true);
  assert.equal(q(widget, '.wayfindr-widget__rating-label').textContent, 'Tell us more');

  q(widget, '.wayfindr-widget__rating-score[data-score="4"]').click();
  assert.equal(q(widget, '.wayfindr-widget__rating-score[data-score="4"]').getAttribute('aria-pressed'), 'true');
  await submit(widget);

  assert.equal(sent.find((r) => r.url.includes('/rating')).body.score, '4');
});

test('a template that asks for no comment shows no comment box and sends none', async () => {
  const { widget, sent } = widgetWith();
  await openPanel(widget);

  assert.equal(q(widget, '.wayfindr-widget__rating-comment').hidden, true);
  assert.equal(q(widget, '.wayfindr-widget__rating-label').hidden, true);

  q(widget, '.wayfindr-widget__rating-comment').value = 'typed anyway';
  q(widget, '.wayfindr-widget__rating-score[data-score="good"]').click();
  await submit(widget);

  assert.equal(sent.find((r) => r.url.includes('/rating')).body.comment, null);
});

test('the visitor’s own language where the site wrote it, English where it did not', async () => {
  // Italian has no widget catalogue, but the site wrote Italian wording.
  const italian = widgetWith({ languages: ['it-IT'] });
  await openPanel(italian.widget);
  assert.equal(q(italian.widget, '.wayfindr-widget__rating-intro').textContent, 'Abbiamo risolto?');
  assert.equal(q(italian.widget, '.wayfindr-widget__rating-score[data-score="good"]').textContent, 'Sorted');

  const german = widgetWith({ locale: 'de' });
  await openPanel(german.widget);
  assert.equal(q(german.widget, '.wayfindr-widget__rating-intro').textContent, 'Did we sort it out?');
  assert.equal(q(german.widget, '.wayfindr-widget__rating-agent-question').textContent, 'Und Ada — wie war es?');
  assert.equal(q(german.widget, '.wayfindr-widget__rating-score[data-score="bad"]').textContent, 'Eher nicht');
});

test('site wording and the agent name are text, and a name with braces is not a placeholder', async () => {
  const definition = { ...THREE, overall: { en: '<img src=x onerror=alert(1)>' } };
  const { widget } = widgetWith({ definition, agent: { ref: 'r', name: '{name}<b>' } });
  await openPanel(widget);

  assert.equal(q(widget, '.wayfindr-widget__rating-intro').querySelector('img'), null);
  assert.equal(q(widget, '.wayfindr-widget__rating-agent-question').textContent, 'And {name}<b> — how did they do?');
});

test('a version published mid-survey cannot change the open prompt’s scale or answers', async () => {
  const { widget, sent } = widgetWith();
  await openPanel(widget);

  q(widget, '.wayfindr-widget__rating-score[data-score="bad"]').click();
  q(widget, '.wayfindr-widget__rating-agent-score[data-agent-score="ok"]').click();

  // A five-star version is published; minimise and reopen refreshes bootstrap.
  widget.publish(FIVE, 4);
  q(widget, '.wayfindr-widget__close').click();
  await settle();
  await openPanel(widget);

  assert.deepEqual(qa(widget, '.wayfindr-widget__rating-score').map((b) => b.getAttribute('data-score')), ['good', 'ok', 'bad']);
  assert.equal(q(widget, '.wayfindr-widget__rating-score[data-score="bad"]').getAttribute('aria-pressed'), 'true');
  await submit(widget);

  const post = sent.find((r) => r.url.includes('/rating'));
  assert.equal(post.body.template_version, 3);
  assert.equal(post.body.score, 'bad');
  assert.equal(post.body.agent_score, 'ok');
});

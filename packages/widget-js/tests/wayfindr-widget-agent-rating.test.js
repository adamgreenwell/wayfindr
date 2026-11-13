const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

// The starter template asks two separate things: how the experience went, and
// how the help from ONE named agent was (#1156). The agent question is optional,
// is asked only when the server names exactly one agent for the close, and is
// sent with what the visitor was actually shown.

function jsonResponse(status, payload) {
  return { ok: status >= 200 && status < 300, status, json: async () => payload };
}

function memoryStorage(seed) {
  const values = new Map(Object.entries(seed || {}));

  return {
    getItem: (key) => (values.has(key) ? values.get(key) : null),
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
    values,
  };
}

async function settle() {
  for (let i = 0; i < 4; i += 1) {
    await new Promise((resolve) => setImmediate(resolve));
  }
}

const STARTER = { asks: true, intro: null, template: { key: 'starter', version: 1 } };
const ADA = { ref: 'agent-ref-ada', name: 'Ada Agent' };

function widgetWith({ rating = STARTER, agent = ADA, locale = null, storage = null } = {}) {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });

  const sent = [];
  let episode = 'episode-1';
  let answered = false;
  const store = storage || memoryStorage({
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
    locale,
    storage: store,
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: async (url, init) => {
      if (url.includes('/cobrowse')) {
        return jsonResponse(200, { data: { cobrowse: { state: 'unavailable' } } });
      }

      sent.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

      if (url.includes('/rating')) {
        answered = true;

        return jsonResponse(201, { data: { rating: { score: 'good' } } });
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
              status: 'closed',
              awaiting_rating: ! answered,
              rating_episode: episode,
              rating_agent: agent,
            },
            messages: [],
            message: { id: 1 },
          },
        });
      }

      return jsonResponse(200, { data: {} });
    },
  });

  widget.nextClose = (next) => { episode = next; answered = false; };

  return { widget, sent, store };
}

async function openPanel(widget) {
  widget.root.querySelector('.wayfindr-widget__launcher').click();
  await settle();
}

const q = (widget, selector) => widget.root.querySelector(selector);
const agentBlock = (widget) => q(widget, '.wayfindr-widget__rating-agent');
const agentScore = (widget, value) => q(widget, '.wayfindr-widget__rating-agent-score[data-agent-score="' + value + '"]');
const ratingPosts = (sent) => sent.filter((request) => request.url.includes('/rating'));

async function answerOverall(widget, value) {
  q(widget, '.wayfindr-widget__rating-score[data-score="' + value + '"]').click();
  q(widget, '.wayfindr-widget__rating').dispatchEvent(new widget.root.ownerDocument.defaultView.Event('submit', { cancelable: true }));
  await settle();
}

test('the starter template names the one agent and asks about them separately', async () => {
  const { widget, sent } = widgetWith();
  await openPanel(widget);

  assert.equal(agentBlock(widget).hidden, false);
  assert.equal(q(widget, '.wayfindr-widget__rating-agent-question').textContent, 'How was the help you received from Ada Agent?');
  assert.equal(q(widget, '.wayfindr-widget__rating-intro').textContent, 'How was your overall support experience?');

  agentScore(widget, 'bad').click();
  await answerOverall(widget, 'good');

  const [post] = ratingPosts(sent);
  assert.equal(post.body.score, 'good');
  assert.equal(post.body.template, 'starter');
  assert.equal(post.body.template_version, 1);
  assert.equal(post.body.agent, 'agent-ref-ada');
  assert.equal(post.body.agent_score, 'bad');
});

test('the agent question is optional: overall feedback goes without it', async () => {
  const { widget, sent } = widgetWith();
  await openPanel(widget);

  await answerOverall(widget, 'ok');

  const [post] = ratingPosts(sent);
  assert.equal(post.body.score, 'ok');
  // Asked about the agent, but not answered: skipped, not a score.
  assert.equal(post.body.agent, 'agent-ref-ada');
  assert.equal(post.body.agent_score, null);
});

test('not applicable is an answer, and pressing it again clears it', async () => {
  const { widget, sent } = widgetWith();
  await openPanel(widget);

  agentScore(widget, 'not_applicable').click();
  assert.equal(agentScore(widget, 'not_applicable').getAttribute('aria-pressed'), 'true');
  assert.equal(agentScore(widget, 'not_applicable').textContent, 'Not applicable');

  agentScore(widget, 'not_applicable').click();
  assert.equal(agentScore(widget, 'not_applicable').getAttribute('aria-pressed'), 'false');

  agentScore(widget, 'not_applicable').click();
  await answerOverall(widget, 'good');
  assert.equal(ratingPosts(sent)[0].body.agent_score, 'not_applicable');
});

test('no agent named for the close means no agent question and no agent in the answer', async () => {
  const { widget, sent } = widgetWith({ agent: null });
  await openPanel(widget);

  assert.equal(agentBlock(widget).hidden, true);
  await answerOverall(widget, 'good');

  const [post] = ratingPosts(sent);
  assert.equal(post.body.template, 'starter');
  assert.equal(post.body.agent, null);
  assert.equal(post.body.agent_score, null);
});

test('a site on the single question never asks about the agent, whatever the server names', async () => {
  const { widget, sent } = widgetWith({ rating: { asks: true, intro: null, template: { key: 'overall', version: 1 } } });
  await openPanel(widget);

  assert.equal(agentBlock(widget).hidden, true);
  assert.equal(q(widget, '.wayfindr-widget__rating-intro').textContent, 'How did that go?');
  await answerOverall(widget, 'bad');

  const [post] = ratingPosts(sent);
  assert.equal(post.body.template, 'overall');
  assert.equal(post.body.agent, null);
});

test('the agent name is text, never markup', async () => {
  const { widget } = widgetWith({ agent: { ref: 'r', name: '<img src=x onerror=alert(1)>' } });
  await openPanel(widget);

  const question = q(widget, '.wayfindr-widget__rating-agent-question');
  assert.equal(question.querySelector('img'), null);
  assert.match(question.textContent, /<img src=x onerror=alert\(1\)>/);
});

test('skipping hides that close in this browser, sends nothing, and a new close asks again', async () => {
  const { widget, sent, store } = widgetWith();
  await openPanel(widget);

  q(widget, '.wayfindr-widget__rating-skip').click();
  await settle();

  assert.equal(q(widget, '.wayfindr-widget__rating').hidden, true);
  assert.equal(ratingPosts(sent).length, 0);
  assert.equal(store.values.get('wayfindr:site_public_docs:rating-skipped'), 'episode-1');

  // The same close after a reload stays skipped.
  const reloaded = widgetWith({ storage: store });
  await openPanel(reloaded.widget);
  assert.equal(q(reloaded.widget, '.wayfindr-widget__rating').hidden, true);

  // A reopen-and-close is new work, and is asked about afresh.
  reloaded.widget.nextClose('episode-2');
  const fresh = widgetWith({ storage: store });
  fresh.widget.nextClose('episode-2');
  await openPanel(fresh.widget);
  assert.equal(q(fresh.widget, '.wayfindr-widget__rating').hidden, false);
});

test('the agent question speaks German from the catalogue', async () => {
  const { widget } = widgetWith({ locale: 'de' });
  await openPanel(widget);

  assert.equal(q(widget, '.wayfindr-widget__rating-agent-question').textContent, 'Wie war die Hilfe von Ada Agent?');
  assert.equal(agentScore(widget, 'not_applicable').textContent, 'Trifft nicht zu');
  assert.equal(q(widget, '.wayfindr-widget__rating-skip').textContent, 'Überspringen');
});

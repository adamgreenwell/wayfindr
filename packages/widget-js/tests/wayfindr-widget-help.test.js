const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

// An answer a visitor can find for themselves, offered where they already are.
// Articles arrive as blocks and are built as elements -- the widget has never
// rendered server-authored markup and does not start here.

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
  // A macrotask as well as the microtask rounds: the search is debounced
  // through setTimeout, which setImmediate alone never lets fire.
  for (let i = 0; i < 4; i += 1) {
    await new Promise((resolve) => setTimeout(resolve, 0));
    await new Promise((resolve) => setImmediate(resolve));
  }
}

function widgetWithHelp({ available = true, articles = [], blocks = [] } = {}) {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/',
  });
  const asked = [];

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: memoryStorage({
      'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
      'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
    }),
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    // Zero so a test need not wait on a debounce timer.
    helpSearchDebounceMs: 0,
    fetch: async (url) => {
      asked.push(url);

      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: {
            site: {
              public_key: 'site_public_shop',
              settings: {},
              color: 'blue',
              articles: { available },
            },
            visitor: { anonymous_id: 'anon-shop', token: 'visitor-token-shop' },
          },
        });
      }

      if (url.includes('/api/widget/articles/')) {
        return jsonResponse(200, { data: { article: { slug: 'refunds', title: 'Refunds', blocks } } });
      }

      if (url.includes('/api/widget/articles')) {
        return jsonResponse(200, { data: { articles } });
      }

      if (url.includes('/cobrowse')) {
        return jsonResponse(200, { data: { cobrowse: { state: 'unavailable' } } });
      }

      return jsonResponse(200, { data: {} });
    },
  });

  return { widget, dom, asked };
}

const q = (widget, sel) => widget.root.querySelector(sel);

test('a desk that has written nothing does not grow a search box', async () => {
  const { widget, asked } = widgetWithHelp({ available: false });

  await widget.open();
  await settle();

  assert.equal(q(widget, '.wayfindr-widget__help').hidden, true);
  // And it did not ask, either: bootstrap already answered.
  assert.equal(asked.some((url) => url.includes('/api/widget/articles')), false);
});

test('the search appears when there is something to find, without asking twice', async () => {
  const { widget, asked } = widgetWithHelp({ available: true });

  await widget.open();
  await settle();

  assert.equal(q(widget, '.wayfindr-widget__help').hidden, false);
  assert.equal(asked.filter((url) => url.includes('/api/widget/articles')).length, 0,
    'opening the panel costs no extra request');
});

test('typing finds matching answers and opening one builds it as elements', async () => {
  const { widget, dom } = widgetWithHelp({
    articles: [{ slug: 'refunds', title: 'How refunds work' }],
    blocks: [
      { type: 'heading', text: 'Refunds' },
      { type: 'paragraph', spans: [{ text: 'Email ' }, { text: 'us', href: 'mailto:help@example.test' }, { text: ' today.' }] },
      { type: 'list', items: [[{ text: 'Keep the receipt' }]] },
    ],
  });

  await widget.open();
  await settle();

  const input = q(widget, '.wayfindr-widget__help-input');
  input.value = 'refund';
  input.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
  await settle();

  const result = q(widget, '.wayfindr-widget__help-result');
  assert.equal(result.textContent, 'How refunds work');

  result.click();
  await settle();

  const article = q(widget, '.wayfindr-widget__help-blocks');
  assert.equal(article.querySelector('h3').textContent, 'Refunds');
  assert.equal(article.querySelector('li').textContent, 'Keep the receipt');
  assert.equal(article.querySelector('p').textContent, 'Email us today.');

  const link = article.querySelector('a');
  assert.equal(link.getAttribute('href'), 'mailto:help@example.test');
  // A help article must not navigate the visitor away from the page they came
  // to for help, and must not hand the opened tab a handle back.
  assert.equal(link.getAttribute('target'), '_blank');
  assert.equal(link.getAttribute('rel'), 'noopener noreferrer nofollow');
});

test('a block type this widget does not know renders as nothing, not as markup', async () => {
  // An older widget will meet articles written by a newer server. Losing a
  // block is the acceptable failure; rendering something it cannot judge is not.
  const { widget, dom } = widgetWithHelp({
    articles: [{ slug: 'refunds', title: 'Refunds' }],
    blocks: [
      { type: 'video', src: 'https://example.test/x.mp4' },
      { type: 'raw', html: '<img src=x onerror=alert(1)>' },
      { type: 'paragraph', spans: [{ text: 'Still readable.' }] },
    ],
  });

  await widget.open();
  await settle();

  const input = q(widget, '.wayfindr-widget__help-input');
  input.value = 'refund';
  input.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
  await settle();

  q(widget, '.wayfindr-widget__help-result').click();
  await settle();

  const article = q(widget, '.wayfindr-widget__help-blocks');
  assert.equal(article.textContent.trim(), 'Still readable.');
  assert.equal(article.querySelector('img'), null);
  assert.equal(article.innerHTML.includes('onerror'), false);
});

test('a search that finds nothing says so rather than sitting empty', async () => {
  const { widget, dom } = widgetWithHelp({ articles: [] });

  await widget.open();
  await settle();

  const input = q(widget, '.wayfindr-widget__help-input');
  input.value = 'nothing like this';
  input.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
  await settle();

  const status = q(widget, '.wayfindr-widget__help-status');
  assert.equal(status.hidden, false);
  assert.match(status.textContent, /Send a message and support will answer/);
});

test('markup inside an article is shown as the characters it is, not run', async () => {
  // The load-bearing guard. Nothing upstream strips angle brackets -- an
  // article may legitimately explain what a <script> tag is -- so the widget is
  // the last place that decides whether those characters are text or markup.
  const { widget, dom } = widgetWithHelp({
    articles: [{ slug: 'refunds', title: 'Refunds' }],
    blocks: [
      { type: 'heading', text: '<script>alert(1)</script>' },
      { type: 'paragraph', spans: [{ text: '<img src=x onerror=alert(2)>' }, { text: ' and ', strong: true }] },
      { type: 'list', items: [[{ text: '<b>not bold</b>' }]] },
    ],
  });

  await widget.open();
  await settle();

  const input = q(widget, '.wayfindr-widget__help-input');
  input.value = 'refund';
  input.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
  await settle();

  q(widget, '.wayfindr-widget__help-result').click();
  await settle();

  const article = q(widget, '.wayfindr-widget__help-blocks');

  assert.equal(article.querySelector('script'), null);
  assert.equal(article.querySelector('img'), null);
  assert.equal(article.querySelector('b'), null);

  // The words survive: refusing to run it is not the same as swallowing it.
  assert.match(article.textContent, /<script>alert\(1\)<\/script>/);
  assert.match(article.textContent, /<img src=x onerror=alert\(2\)>/);
  assert.match(article.textContent, /<b>not bold<\/b>/);
});

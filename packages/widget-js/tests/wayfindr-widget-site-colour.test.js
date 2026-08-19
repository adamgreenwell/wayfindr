const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

// Site colour reaches the visitor (ADR 0014). The operator picks a key, the
// server sends the key, and the widget resolves it through --wf-site-<key> so
// the theme-tuned variants apply without the widget knowing any hex.

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
  await new Promise((resolve) => setImmediate(resolve));
  await new Promise((resolve) => setImmediate(resolve));
  await new Promise((resolve) => setImmediate(resolve));
}

function widgetWithSiteColor(color) {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });

  const site = { public_key: 'site_public_docs', settings: {} };

  if (color !== undefined) {
    site.color = color;
  }

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    storage: memoryStorage({
      'wayfindr:site_public_docs:anonymous-id': 'anon-docs',
      'wayfindr:site_public_docs:visitor-token': 'visitor-token-docs',
      'wayfindr:site_public_docs:support-code': 'WF-DOCS',
    }),
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: async (url) => {
      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: { site, visitor: { anonymous_id: 'anon-docs', token: 'visitor-token-docs' } },
        });
      }

      if (url.includes('/cobrowse')) {
        return jsonResponse(200, { data: { cobrowse: { state: 'unavailable' } } });
      }

      if (url.includes('/messages')) {
        return jsonResponse(200, {
          data: { conversation: { support_code: 'WF-DOCS', status: 'open' }, messages: [] },
        });
      }

      return jsonResponse(200, { data: {} });
    },
  });

  return widget;
}

test('the widget wears its site colour once bootstrap answers', async () => {
  const widget = widgetWithSiteColor('violet');

  await settle();

  assert.equal(
    widget.root.style.getPropertyValue('--wf-site-accent'),
    'var(--wf-site-violet)',
  );
});

test('a key outside the palette is ignored rather than interpolated', async () => {
  // This value reaches a CSS custom property NAME on a page Wayfindr does not
  // own. The server constrains it, and that is exactly why the widget checks
  // again rather than trusting the response.
  const widget = widgetWithSiteColor('red); background-image: url(https://evil.example/x.png');

  await settle();

  assert.equal(widget.root.style.getPropertyValue('--wf-site-accent'), '');
  assert.ok(!widget.root.getAttribute('style') || !widget.root.getAttribute('style').includes('evil.example'));
});

test('a missing colour leaves the widget on its brand default', async () => {
  const widget = widgetWithSiteColor(undefined);

  await settle();

  // Unset rather than blank: the panel rule falls back to var(--wf-brand),
  // so an older server that does not send a colour still renders correctly.
  assert.equal(widget.root.style.getPropertyValue('--wf-site-accent'), '');
});

test('the panel accent falls back to the brand token', () => {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });

  Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-docs',
    storage: memoryStorage(),
    fetch: async () => jsonResponse(404, { message: 'Not used' }),
  });

  const styles = dom.window.document.querySelector('#wayfindr-widget-styles').textContent;

  assert.match(styles, /border-top:3px solid var\(--wf-site-accent,var\(--wf-brand\)\)/);
});

// --- Theme (ADR 0014, step 6) ---------------------------------------------

function widgetStyles() {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });

  Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-docs',
    storage: memoryStorage(),
    fetch: async () => jsonResponse(404, { message: 'Not used' }),
  });

  return dom.window.document.querySelector('#wayfindr-widget-styles').textContent;
}

test('every colour in the widget stylesheet resolves through a token', () => {
  const styles = widgetStyles();

  const literals = styles
    .split(/[;{}]/)
    // A `--wf-*` declaration IS the token definition, which is the one place a
    // literal belongs. Shadows are exempt too: they sit on a page Wayfindr does
    // not own, so they are tuned for an unknown background rather than for
    // either of our themes.
    .filter((rule) => !rule.trim().startsWith('--wf-') && !rule.includes('box-shadow'))
    .filter((rule) => /#[0-9a-fA-F]{3,8}\b|rgba?\(/.test(rule));

  assert.deepEqual(literals, []);
});

test('the composer sets a background as well as a text colour', () => {
  // It set only `color`. A visitor whose system is in dark mode therefore typed
  // near-white text onto the browser default white, and could not read it.
  const styles = widgetStyles();
  const rule = styles.match(/\.wayfindr-widget__textarea\{[^}]*\}/)[0];

  assert.match(rule, /background:var\(--wf-surface\)/);
  assert.match(rule, /color:var\(--wf-ink\)/);
});

test('the widget follows the visitor system colour scheme', () => {
  const styles = widgetStyles();

  assert.match(styles, /@media \(prefers-color-scheme:dark\)\{\.wayfindr-widget/);
});

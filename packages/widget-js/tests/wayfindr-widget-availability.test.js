const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

// Telling a visitor nobody is home, before they start typing, is the whole
// point: it prevents the unanswered conversation the alert would otherwise
// chase. The desk's schedule stays server-side; the widget is told only that
// it is away and when it returns.

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

function widgetWithAvailability(availability) {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });

  const site = { public_key: 'site_public_docs', settings: {}, color: 'blue' };

  if (availability !== undefined) {
    site.availability = availability;
  }

  return Wayfindr.init({
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
}

function awayEl(widget) {
  return widget.root.querySelector('.wayfindr-widget__away');
}

test('an away desk tells the visitor, in the operator’s own words', async () => {
  const widget = widgetWithAvailability({
    away: true,
    message: 'Wir sind gerade geschlossen.',
    opens_at: '2026-08-31T09:00:00+01:00',
    timezone: 'Europe/London',
  });

  await settle();

  const el = awayEl(widget);

  assert.equal(el.hidden, false);
  // Operator copy is shown as typed. Nothing here is an English literal the
  // widget invented, which is why this works before any i18n layer exists.
  assert.ok(el.textContent.includes('Wir sind gerade geschlossen.'));
});

test('an open desk shows no notice at all', async () => {
  const widget = widgetWithAvailability({ away: false, message: null, opens_at: null, timezone: 'UTC' });

  await settle();

  assert.equal(awayEl(widget).hidden, true);
  assert.equal(awayEl(widget).textContent, '');
});

test('a site with no availability behaves as it always did', async () => {
  // Older servers send no availability key. The widget must not decide that
  // silence means closed.
  const widget = widgetWithAvailability(undefined);

  await settle();

  assert.equal(awayEl(widget).hidden, true);
});

test('an away desk with no operator message still says something useful', async () => {
  const widget = widgetWithAvailability({ away: true, message: null, opens_at: null, timezone: 'UTC' });

  await settle();

  const el = awayEl(widget);

  assert.equal(el.hidden, false);
  assert.ok(el.textContent.toLowerCase().includes('away'));
});

test('the away message is set as text, so operator copy cannot inject markup', async () => {
  const widget = widgetWithAvailability({
    away: true,
    message: '<img src=x onerror="window.__owned = true">',
    opens_at: null,
    timezone: 'UTC',
  });

  await settle();

  const el = awayEl(widget);

  assert.equal(el.querySelector('img'), null);
  assert.ok(el.textContent.includes('<img'));
});

test('an unparseable return time is dropped rather than shown raw', async () => {
  const widget = widgetWithAvailability({
    away: true,
    message: 'Closed.',
    opens_at: 'not-a-date',
    timezone: 'UTC',
  });

  await settle();

  const text = awayEl(widget).textContent;

  assert.ok(text.includes('Closed.'));
  assert.ok(!text.includes('not-a-date'));
});

test('reopening the panel re-asks whether the desk is still open', async () => {
  // A tab left sitting across closing time would otherwise still show the desk
  // as open, and one opened while away would keep saying away after support
  // came back. Both silent, both wrong exactly when the visitor decides to type.
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/',
  });

  let away = false;
  let bootstraps = 0;

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
      if (url.endsWith('/api/widget/bootstrap')) {
        bootstraps += 1;

        return jsonResponse(200, {
          data: {
            site: {
              public_key: 'site_public_docs',
              settings: {},
              color: 'blue',
              availability: { away, message: 'Closed for the evening.', opens_at: null, timezone: 'UTC' },
            },
            visitor: { anonymous_id: 'anon-docs', token: 'visitor-token-docs' },
          },
        });
      }

      if (url.includes('/cobrowse')) {
        return jsonResponse(200, { data: { cobrowse: { state: 'unavailable' } } });
      }

      return jsonResponse(200, { data: {} });
    },
  });

  const launcher = widget.root.querySelector('.wayfindr-widget__launcher');
  const panel = widget.root.querySelector('.wayfindr-widget__panel');

  launcher.click();
  await settle();

  assert.equal(panel.querySelector('.wayfindr-widget__away').hidden, true);
  const afterFirstOpen = bootstraps;

  // Closing time passes while the tab sits there.
  away = true;

  widget.root.querySelector('.wayfindr-widget__close').click();
  launcher.click();
  await settle();

  assert.ok(bootstraps > afterFirstOpen, 'reopening should re-ask the server');
  assert.equal(panel.querySelector('.wayfindr-widget__away').hidden, false);
});

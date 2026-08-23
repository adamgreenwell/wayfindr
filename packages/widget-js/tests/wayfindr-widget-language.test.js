const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

// A visitor did not choose this product and cannot be asked to read a language
// they do not speak. The order the widget resolves a language in is therefore
// the whole feature: the host page first, because an application that signed
// someone in knows what they picked; then the visitor's own browser, because
// they answered for themselves; then the operator's site default, which is only
// ever a guess about who visits.

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

function widgetSpeaking({ requested, browser, siteLocale } = {}) {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/',
  });

  const site = { public_key: 'site_public_shop', settings: {}, color: 'blue' };

  if (siteLocale !== undefined) {
    site.locale = siteLocale;
  }

  return Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: browser === undefined ? { languages: [] } : { languages: [].concat(browser) },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    locale: requested,
    storage: memoryStorage({
      'wayfindr:site_public_shop:anonymous-id': 'anon-shop',
      'wayfindr:site_public_shop:visitor-token': 'visitor-token-shop',
    }),
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: async (url) => {
      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: { site, visitor: { anonymous_id: 'anon-shop', token: 'visitor-token-shop' } },
        });
      }

      if (url.includes('/cobrowse')) {
        return jsonResponse(200, { data: { cobrowse: { state: 'unavailable' } } });
      }

      if (url.includes('/messages')) {
        return jsonResponse(200, { data: { conversation: { support_code: 'WF-SHOP', status: 'open' }, messages: [] } });
      }

      return jsonResponse(200, { data: {} });
    },
  });
}

const chrome = (widget) => ({
  launcher: widget.root.querySelector('.wayfindr-widget__launcher'),
  send: widget.root.querySelector('.wayfindr-widget__send'),
  label: widget.root.querySelector('.wayfindr-widget__label'),
  textarea: widget.root.querySelector('.wayfindr-widget__textarea'),
  notice: widget.root.querySelector('.wayfindr-widget__notice-copy'),
  close: widget.root.querySelector('.wayfindr-widget__close'),
});

test('every catalogue answers exactly the questions English answers', () => {
  const catalogues = Wayfindr.messages;
  const english = Object.keys(catalogues.en).sort();

  assert.ok(english.length > 50, 'the English catalogue should be the full set');

  for (const [locale, table] of Object.entries(catalogues)) {
    // A missing key falls back to English, which is exactly the failure that
    // survives review: the widget looks translated and one sentence is not.
    assert.deepEqual(Object.keys(table).sort(), english, `${locale} does not match en`);

    for (const [key, value] of Object.entries(table)) {
      assert.equal(typeof value, 'string', `${locale}.${key} is not a string`);
      assert.notEqual(value.trim(), '', `${locale}.${key} is empty`);
    }
  }
});

test('placeholders survive translation', () => {
  const catalogues = Wayfindr.messages;
  const placeholders = (value) => (value.match(/\{\w+\}/g) || []).sort();

  for (const [locale, table] of Object.entries(catalogues)) {
    for (const [key, value] of Object.entries(table)) {
      // A translation that drops {code} silently loses the support code the
      // visitor needs to quote back.
      assert.deepEqual(placeholders(value), placeholders(catalogues.en[key]), `${locale}.${key} placeholders differ`);
    }
  }
});

test('the chrome is drawn in the language before anything is fetched', () => {
  // The panel is built at init and bootstrap has not happened yet, so a locale
  // that only arrived with the site payload would flash English first.
  const widget = widgetSpeaking({ requested: 'de' });
  const el = chrome(widget);

  assert.equal(el.launcher.textContent, 'Chat mit dem Support');
  assert.equal(el.send.textContent, 'Nachricht senden');
  assert.equal(el.label.textContent, 'Wie können wir helfen?');
  assert.equal(el.textarea.getAttribute('placeholder'), 'Nachricht eingeben …');
  assert.equal(el.close.getAttribute('aria-label'), 'Support-Chat schließen');
  assert.equal(widget.root.lang, 'de');
  assert.equal(widget.root.dir, 'ltr');
});

test('the visitor’s browser outranks the operator’s site default', async () => {
  const widget = widgetSpeaking({ browser: ['de-AT'], siteLocale: 'en' });

  await widget.open();
  await settle();

  // de-AT is not a catalogue; a regional variant still falls back to its
  // language rather than to English.
  assert.equal(widget.root.lang, 'de');
  assert.equal(chrome(widget).launcher.textContent, 'Chat mit dem Support');
});

test('the host page outranks the browser', () => {
  const widget = widgetSpeaking({ requested: 'de', browser: ['en-GB'] });

  assert.equal(widget.root.lang, 'de');
});

test('a language nobody speaks falls through instead of failing', () => {
  // An unsupported request is not an error: the next answer down is still a
  // language the visitor reads.
  const widget = widgetSpeaking({ requested: 'kl-GL', browser: ['de'] });

  assert.equal(widget.root.lang, 'de');

  const english = widgetSpeaking({ requested: 'kl-GL', browser: ['kl-GL'] });

  assert.equal(english.root.lang, 'en');
  assert.equal(chrome(english).launcher.textContent, 'Chat with support');
});

test('the site default applies when nobody else has answered', async () => {
  const widget = widgetSpeaking({ siteLocale: 'de' });

  // Nothing said German yet, so the chrome starts in English.
  assert.equal(chrome(widget).launcher.textContent, 'Chat with support');

  await widget.open();
  await settle();

  assert.equal(widget.root.lang, 'de');
  assert.equal(chrome(widget).launcher.textContent, 'Chat mit dem Support');
  assert.equal(chrome(widget).send.textContent, 'Nachricht senden');
});

test('retranslating keeps what the visitor typed', async () => {
  const widget = widgetSpeaking({ siteLocale: 'de' });
  const el = chrome(widget);

  el.textarea.value = 'Wo ist meine Bestellung?';

  await widget.open();
  await settle();

  // Rebuilding the panel would have been the easy way to retranslate it, and
  // would have thrown away the half-written message.
  assert.equal(el.textarea.value, 'Wo ist meine Bestellung?');
  assert.equal(widget.root.lang, 'de');
});

test('a label the host page supplied is left alone', async () => {
  const dom = new JSDOM('<!doctype html><html><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/',
  });

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages: [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    launcherLabel: 'Brauchen Sie Hilfe?',
    storage: memoryStorage({}),
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    fetch: async (url) => {
      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: { site: { public_key: 'site_public_shop', settings: {}, locale: 'de' }, visitor: { anonymous_id: 'a', token: 't' } },
        });
      }

      return jsonResponse(200, { data: {} });
    },
  });

  await widget.open();
  await settle();

  // The operator wrote this one; a language change is not licence to overwrite
  // copy the host page owns.
  assert.equal(widget.root.querySelector('.wayfindr-widget__launcher').textContent, 'Brauchen Sie Hilfe?');
  assert.equal(widget.root.lang, 'de');
});

test('direction follows the language actually rendered, not the one asked for', () => {
  // Wayfindr ships no right-to-left catalogue yet. Asking for Arabic therefore
  // renders English, and English inside a right-to-left box is worse than
  // English in a left-to-right one -- so the direction follows the catalogue
  // that was drawn, never the request that could not be honoured.
  const arabic = widgetSpeaking({ requested: 'ar' });

  assert.equal(arabic.root.lang, 'en');
  assert.equal(arabic.root.dir, 'ltr');

  const german = widgetSpeaking({ requested: 'de' });

  assert.equal(german.root.dir, 'ltr');
});

test('the direction of a language is known before its catalogue exists', () => {
  // The mechanism the moment an RTL catalogue is added: the widget sets dir on
  // its own root, so a right-to-left panel can sit inside a left-to-right page
  // without touching the host's direction.
  assert.equal(Wayfindr.textDirection('ar'), 'rtl');
  assert.equal(Wayfindr.textDirection('he-IL'), 'rtl');
  assert.equal(Wayfindr.textDirection('fa'), 'rtl');
  assert.equal(Wayfindr.textDirection('ur-PK'), 'rtl');
  assert.equal(Wayfindr.textDirection('de'), 'ltr');
  assert.equal(Wayfindr.textDirection('en-GB'), 'ltr');
  assert.equal(Wayfindr.textDirection(''), 'ltr');
  assert.equal(Wayfindr.textDirection(undefined), 'ltr');
});

test('the panel anchors to the inline edge, so it flips with direction', () => {
  const widget = widgetSpeaking({ requested: 'de' });
  const styles = widget.root.ownerDocument.getElementById('wayfindr-widget-styles').textContent;

  // Physical `right` would leave an Arabic widget pinned to the wrong corner.
  assert.ok(styles.includes('inset-inline-end:20px'), 'the launcher should anchor to the inline edge');
  assert.ok(!/\.wayfindr-widget\{position:fixed;right:/.test(styles), 'no physical right on the root');
});

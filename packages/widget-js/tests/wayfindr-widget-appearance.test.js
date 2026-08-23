const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

// The widget is the only part of Wayfindr a customer's own customers ever see.
// Until now the accent was Wayfindr's teal in 22 rules and the site's own
// colour painted a single 3px border.

function jsonResponse(status, payload) {
  return { ok: status >= 200 && status < 300, status, json: async () => payload };
}

async function settle() {
  for (let i = 0; i < 4; i += 1) {
    await new Promise((resolve) => setTimeout(resolve, 0));
    await new Promise((resolve) => setImmediate(resolve));
  }
}

function widgetLooking({ appearance, options = {} } = {}) {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/',
  });

  const site = { public_key: 'site_public_shop', settings: {}, color: 'blue' };

  if (appearance) {
    site.appearance = appearance;
  }

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_shop',
    storage: { getItem: () => null, setItem: () => {}, removeItem: () => {} },
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    helpSearchDebounceMs: 0,
    fetch: async (url) => {
      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: { site, visitor: { anonymous_id: 'a', token: 't' } },
        });
      }

      return jsonResponse(200, { data: {} });
    },
    ...options,
  });

  return { widget, dom };
}

const prop = (widget, name) => widget.root.style.getPropertyValue(name);

test('a site that configures nothing keeps Wayfindr’s own accent', async () => {
  // The server always sends the object; an unconfigured site sends it empty.
  const { widget } = widgetLooking({
    appearance: { accent: null, accent_dark: null, position: 'right', greeting: null, placeholder: null },
  });

  await widget.open();
  await settle();

  assert.equal(prop(widget, '--wf-brand-configured'), '', 'the token is never set, so the fallback stands');
  assert.equal(widget.root.getAttribute('data-wf-launcher'), 'right');
});

test('a server too old to send an appearance changes nothing', async () => {
  // A widget deployed ahead of its server. Leaving the attribute unset is the
  // right failure: the CSS fallback is Wayfindr's own accent either way.
  const { widget } = widgetLooking();

  await widget.open();
  await settle();

  assert.equal(prop(widget, '--wf-brand-configured'), '');
  assert.equal(widget.root.getAttribute('data-wf-launcher'), null);
});

test('a configured accent is applied for both themes at once', async () => {
  // One token drives 22 rules, so pointing --wf-brand at the operator's colour
  // recolours every button, link and focus ring rather than a hairline.
  const { widget } = widgetLooking({
    appearance: {
      accent: '#7C3AED', accent_dark: '#8243EE',
      accent_ink: '#FFFFFF', accent_ink_dark: '#FFFFFF',
      position: 'right', greeting: null, placeholder: null,
    },
  });

  await widget.open();
  await settle();

  assert.equal(prop(widget, '--wf-brand-configured'), '#7C3AED');
  assert.equal(prop(widget, '--wf-brand-configured-dark'), '#8243EE');
  assert.equal(prop(widget, '--wf-brand-ink-configured'), '#FFFFFF');
});

test('the launcher can move to the other corner', async () => {
  const { widget } = widgetLooking({
    appearance: { accent: null, position: 'left', greeting: null, placeholder: null },
  });

  await widget.open();
  await settle();

  assert.equal(widget.root.getAttribute('data-wf-launcher'), 'left');
});

test('operator copy replaces the widget’s own, and a host page still outranks both', async () => {
  const { widget } = widgetLooking({
    appearance: {
      accent: null, position: 'right',
      greeting: 'Ask the Northwind team', placeholder: 'What do you need?',
    },
  });

  await widget.open();
  await settle();

  assert.equal(widget.root.querySelector('.wayfindr-widget__header strong').textContent, 'Ask the Northwind team');
  assert.equal(widget.root.querySelector('.wayfindr-widget__textarea').getAttribute('placeholder'), 'What do you need?');

  // A host page that passed its own title keeps it: it is the more specific
  // answer, and the same rule the launcher label already follows.
  const { widget: hosted } = widgetLooking({
    appearance: { accent: null, position: 'right', greeting: 'Ask the Northwind team', placeholder: null },
    options: { title: 'Host page title' },
  });

  await hosted.open();
  await settle();

  assert.equal(hosted.root.querySelector('.wayfindr-widget__header strong').textContent, 'Host page title');
});

test('operator copy is shown as characters, not as markup', async () => {
  const { widget } = widgetLooking({
    appearance: {
      accent: null, position: 'right',
      greeting: '<img src=x onerror=alert(1)>', placeholder: null,
    },
  });

  await widget.open();
  await settle();

  const heading = widget.root.querySelector('.wayfindr-widget__header strong');

  assert.equal(heading.querySelector('img'), null);
  assert.equal(heading.textContent, '<img src=x onerror=alert(1)>');
});

test('the brand token actually reads the configured one, in both themes', async () => {
  // Setting a custom property nothing consumes would satisfy every test above
  // while changing nothing a visitor sees. This asserts the wiring itself:
  // --wf-brand must be declared IN TERMS OF the configured value, with
  // Wayfindr's own as the fallback for a site that sets nothing.
  const { widget, dom } = widgetLooking({
    appearance: {
      accent: '#7C3AED', accent_dark: '#8243EE',
      accent_ink: '#FFFFFF', accent_ink_dark: '#FFFFFF',
      position: 'right', greeting: null, placeholder: null,
    },
  });

  await widget.open();
  await settle();

  // JSDOM does not resolve var(), so the declaration itself comes back — which
  // is the thing under test.
  const declared = dom.window.getComputedStyle(widget.root).getPropertyValue('--wf-brand');

  assert.match(declared, /var\(--wf-brand-configured\s*,/);
  assert.match(declared, /#0D6F68/, 'Wayfindr’s own colour remains the fallback');

  const css = [...dom.window.document.querySelectorAll('style')].map((s) => s.textContent).join('');

  // Both dark rules: the prefers-color-scheme query and the explicit attribute.
  assert.equal((css.match(/--wf-brand:var\(--wf-brand-configured-dark,/g) || []).length, 2);
  assert.equal((css.match(/--wf-ink-invert:var\(--wf-brand-ink-configured/g) || []).length, 3);
});

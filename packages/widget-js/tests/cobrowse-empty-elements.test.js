// Rendered-size capture for content-empty elements (#536).
//
// Decorative elements with no content — skeleton blocks, gradient panels,
// dots, spacers — are sized by stylesheet rules that computed-style capture
// does not serialize, so they collapsed to nothing in the replay. The widget
// now captures the rendered pixel box (width/height) for content-empty,
// non-inline elements. Masked elements and form controls stay excluded: their
// box can encode value-derived signals (ch-unit widths, validity styling).
// jsdom has no layout, so computed styles are injected via options.view.

const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

function emptyElementSnapshot(bodyHtml, stylesByKey) {
  const dom = new JSDOM(
    '<!doctype html><html><head><title>T</title></head><body>' + bodyHtml + '</body></html>',
    { url: 'https://host.example.test/page' }
  );

  return Wayfindr.createCobrowseSnapshot(dom.window.document, {
    location: dom.window.location,
    view: {
      getComputedStyle(element) {
        const key = element.getAttribute ? element.getAttribute('data-cs') : null;
        const map = (key && stylesByKey[key]) || {};

        return { getPropertyValue: (property) => map[property] || '' };
      },
    },
  }).html;
}

test('captures the rendered pixel box for content-empty elements', () => {
  const html = emptyElementSnapshot(
    '<div data-cs="wrap"><div data-cs="skeleton"></div><span data-cs="dot"></span></div>',
    {
      skeleton: { display: 'block', width: '480px', height: '120.5px' },
      dot: { display: 'inline-block', width: '8px', height: '8px' },
    }
  );

  assert.match(html, /width:480px;height:120\.5px/);
  assert.match(html, /width:8px;height:8px/);
});

test('does not size elements that have content', () => {
  const html = emptyElementSnapshot(
    '<div data-cs="text">Visible copy.</div><div data-cs="parent"><p>child</p></div>',
    {
      text: { display: 'block', width: '480px', height: '40px' },
      parent: { display: 'block', width: '480px', height: '40px' },
    }
  );

  assert.equal(html.includes('width:480px'), false);
});

test('skips inline elements, which ignore width and height', () => {
  const html = emptyElementSnapshot(
    '<span data-cs="inline-empty"></span>',
    { 'inline-empty': { display: 'inline', width: '32px', height: '32px' } }
  );

  assert.equal(html.includes('width:32px'), false);
});

test('captures only sane pixel values', () => {
  const html = emptyElementSnapshot(
    '<div data-cs="weird"></div><div data-cs="huge"></div><div data-cs="zero"></div>',
    {
      weird: { display: 'block', width: 'auto', height: '50%' },
      huge: { display: 'block', width: '99999px', height: '120px' },
      zero: { display: 'block', width: '0px', height: '0px' },
    }
  );

  assert.equal(html.includes('width:auto'), false);
  assert.equal(html.includes('50%'), false);
  assert.equal(html.includes('99999px'), false);
  assert.equal(html.includes('width:0px'), false);
  assert.equal(html.includes('height:0px'), false);
  // The sane declaration on the same element still comes through.
  assert.match(html, /height:120px/);
});

test('never sizes masked elements or form controls', () => {
  const html = emptyElementSnapshot(
    '<div data-cs="secret" data-secret></div><input data-cs="field" name="card">',
    {
      secret: { display: 'block', width: '300px', height: '80px' },
      field: { display: 'inline-block', width: '240px', height: '32px' },
    }
  );

  assert.equal(html.includes('width:300px'), false);
  assert.equal(html.includes('width:240px'), false);
});

test('never sizes open shadow hosts from their light DOM', () => {
  // A shadow host with no light children is not "empty": its rendered box is
  // derived from shadow content that has not been masked yet at sizing time.
  const dom = new JSDOM(
    '<!doctype html><html><head><title>T</title></head><body><div data-cs="host"></div></body></html>',
    { url: 'https://host.example.test/page' }
  );
  const host = dom.window.document.querySelector('[data-cs="host"]');
  const inner = dom.window.document.createElement('span');

  inner.textContent = 'shadow content';
  host.attachShadow({ mode: 'open' }).appendChild(inner);

  const html = Wayfindr.createCobrowseSnapshot(dom.window.document, {
    location: dom.window.location,
    view: {
      getComputedStyle(element) {
        const key = element.getAttribute ? element.getAttribute('data-cs') : null;
        const map = key === 'host'
          ? { display: 'inline-block', width: '320px', height: '44px' }
          : {};

        return { getPropertyValue: (property) => map[property] || '' };
      },
    },
  }).html;

  assert.equal(html.includes('width:320px'), false);
  assert.equal(html.includes('height:44px'), false);
  // The shadow content itself is still captured through the shadow wrapper.
  assert.match(html, /shadow content/);
});

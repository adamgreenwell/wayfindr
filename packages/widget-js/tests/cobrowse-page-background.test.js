// Page-level background capture for cobrowse snapshots (#535).
//
// The snapshot serializes body.innerHTML, so the page background — the single
// most visible style on many pages — never rides along with element capture.
// The widget reads the background family from the body, falling back
// per-property to the root element (browsers propagate a transparent body's
// background from <html>), under the same gradient-only rules as element
// capture, and reports it as snapshot.bodyStyle. jsdom has no layout, so
// computed styles are injected via options.view.

const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

function pageBackgroundSnapshot(stylesByKey, options) {
  const dom = new JSDOM([
    '<!doctype html><html data-cs="root"><head><title>T</title></head>',
    '<body data-cs="body"><p>Visible copy.</p></body></html>',
  ].join(''), { url: 'https://host.example.test/page' });

  return Wayfindr.createCobrowseSnapshot(dom.window.document, Object.assign({
    location: dom.window.location,
    view: {
      getComputedStyle(element) {
        const key = element.getAttribute ? element.getAttribute('data-cs') : null;
        const map = (key && stylesByKey[key]) || {};

        return { getPropertyValue: (property) => map[property] || '' };
      },
    },
  }, options || {}));
}

const GRID_GRADIENT = 'repeating-linear-gradient(rgba(29, 37, 35, 0.05) 0px, rgba(29, 37, 35, 0.05) 1px, rgba(0, 0, 0, 0) 1px, rgba(0, 0, 0, 0) 24px)';

test('captures the body background color, gradient, and tile size', () => {
  const snapshot = pageBackgroundSnapshot({
    body: {
      'background-color': 'rgb(250, 247, 242)',
      'background-image': GRID_GRADIENT,
      'background-size': '24px 24px',
    },
  });

  assert.equal(
    snapshot.bodyStyle,
    'background-color:rgb(250, 247, 242);background-image:' + GRID_GRADIENT + ';background-size:24px 24px'
  );
});

test('falls back to the root element when the body background is transparent', () => {
  const snapshot = pageBackgroundSnapshot({
    body: { 'background-color': 'rgba(0, 0, 0, 0)' },
    root: { 'background-color': 'rgb(18, 24, 22)' },
  });

  assert.equal(snapshot.bodyStyle, 'background-color:rgb(18, 24, 22)');
});

test('an opaque body never composites the root background over it', () => {
  // In browsers the root background paints the canvas behind the body, not a
  // per-property fallback: html{gradient} body{white} shows white behind the
  // content, so the replay must too.
  const snapshot = pageBackgroundSnapshot({
    body: { 'background-color': 'rgb(255, 255, 255)' },
    root: { 'background-color': 'rgb(18, 24, 22)', 'background-image': GRID_GRADIENT, 'background-size': '24px 24px' },
  });

  assert.equal(snapshot.bodyStyle, 'background-color:rgb(255, 255, 255)');
});

test('never captures url()-bearing page backgrounds', () => {
  const snapshot = pageBackgroundSnapshot({
    body: {
      'background-color': 'rgb(255, 255, 255)',
      'background-image': 'url("https://host.example.test/bg.png")',
    },
  });

  assert.equal(snapshot.bodyStyle, 'background-color:rgb(255, 255, 255)');
  assert.equal(snapshot.bodyStyle.includes('url('), false);
});

test('tile size only rides along with a captured gradient', () => {
  // Without a gradient image there is nothing to tile.
  const sizeOnly = pageBackgroundSnapshot({
    body: { 'background-size': '24px 24px' },
  });

  assert.equal(sizeOnly.bodyStyle, '');

  // The default auto size adds nothing.
  const autoSize = pageBackgroundSnapshot({
    body: { 'background-image': GRID_GRADIENT, 'background-size': 'auto' },
  });

  assert.equal(autoSize.bodyStyle, 'background-image:' + GRID_GRADIENT);
});

test('captures no page background when style capture is disabled', () => {
  const snapshot = pageBackgroundSnapshot({
    body: { 'background-color': 'rgb(250, 247, 242)' },
  }, { captureStyles: false });

  assert.equal(snapshot.bodyStyle, '');
});

// Computed-style capture for cobrowse snapshots (#491 Phase 2).
//
// The widget captures a small allowlist of color/typography/surface computed
// styles so the agent replay preview resembles the visitor page. Inherited
// values are emitted only when they differ from the parent; resource-bearing
// values (url()) are never captured; a styled-element budget bounds payload.
// jsdom has no layout, so computed styles are injected via options.view.

const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

function fakeView(stylesByKey) {
  return {
    getComputedStyle(element) {
      const key = element.getAttribute ? element.getAttribute('data-cs') : null;
      const map = (key && stylesByKey[key]) || {};

      return { getPropertyValue: (property) => map[property] || '' };
    },
  };
}

function snapshotWithStyles(stylesByKey, options) {
  const dom = new JSDOM([
    '<!doctype html><html><head><title>T</title></head><body>',
    '<div data-cs="root">',
    '  <p data-cs="same">inherits</p>',
    '  <p data-cs="diff">changed</p>',
    '  <div data-cs="boxed">box</div>',
    '  <a data-cs="evil">bg</a>',
    '  <input data-cs="field" name="card">',
    '  <div data-cs="hidden-secret" data-secret>secret box</div>',
    '</div>',
    '</body></html>',
  ].join(''), { url: 'https://host.example.test/page' });

  return Wayfindr.createCobrowseSnapshot(dom.window.document, Object.assign({
    location: dom.window.location,
    view: fakeView(stylesByKey),
  }, options || {})).html;
}

const STYLES = {
  root: { color: 'rgb(10, 20, 30)', 'font-family': 'Arial' },
  same: { color: 'rgb(10, 20, 30)', 'font-family': 'Arial' },
  diff: { color: 'rgb(200, 0, 0)', 'font-family': 'Arial' },
  boxed: { color: 'rgb(10, 20, 30)', 'background-color': 'rgb(240, 240, 240)', 'border-radius': '8px' },
  evil: { 'background-color': 'url(https://evil.example/x.png)' },
  // A field whose background is derived from its (sensitive) value/validity.
  field: { 'background-color': 'rgb(255, 0, 0)' },
  // A masked element with a value-derived background.
  'hidden-secret': { 'background-color': 'rgb(0, 255, 0)' },
};

test('captures color, background, and radius into the snapshot', () => {
  const html = snapshotWithStyles(STYLES);

  assert.match(html, /color:rgb\(10, 20, 30\);font-family:Arial/); // root establishes the base
  assert.match(html, /color:rgb\(200, 0, 0\)/);                    // changed child emits its color
  assert.match(html, /background-color:rgb\(240, 240, 240\)/);     // boxed background
  assert.match(html, /border-radius:8px/);                         // boxed radius
});

test('omits inherited styles that match the parent', () => {
  const html = snapshotWithStyles(STYLES);

  // The base color appears once (on root), not repeated on the same-color child.
  assert.equal((html.match(/color:rgb\(10, 20, 30\)/g) || []).length, 1);
});

test('never captures url()-bearing or resource values', () => {
  const html = snapshotWithStyles(STYLES);

  assert.equal(html.includes('url('), false);
  assert.equal(html.includes('evil.example'), false);
});

test('honors the styled-element budget', () => {
  const html = snapshotWithStyles(STYLES, { maxStyledElements: 1 });

  // Only the first captured element keeps styles; later ones degrade to structural.
  assert.match(html, /color:rgb\(10, 20, 30\)/);
  assert.equal(html.includes('border-radius:8px'), false);
});

test('never captures styles for form controls or masked elements', () => {
  // A value-derived style on a sensitive field must not leak through the snapshot
  // even though the field's value itself is masked.
  const html = snapshotWithStyles(STYLES);

  assert.equal(html.includes('rgb(255, 0, 0)'), false); // form control bg skipped
  assert.equal(html.includes('rgb(0, 255, 0)'), false); // masked element bg skipped
});

test('captures nothing when style capture is disabled', () => {
  const html = snapshotWithStyles(STYLES, { captureStyles: false });

  assert.equal(html.includes('background-color'), false);
  assert.equal(html.includes('border-radius'), false);
  assert.equal(html.includes('color:rgb'), false);
});

test('captures layout styles on flex and grid containers only', () => {
  const html = snapshotWithStyles({
    root: { color: 'rgb(10, 20, 30)' },
    // "same" acts as a flex container in this scenario.
    same: {
      color: 'rgb(10, 20, 30)',
      display: 'flex',
      'flex-direction': 'column',
      'justify-content': 'space-between',
      'align-items': 'center',
      gap: '24px',
      padding: '16px 24px',
    },
    // "diff" is a grid container with resolved px tracks.
    diff: {
      color: 'rgb(10, 20, 30)',
      display: 'grid',
      'grid-template-columns': '480px 480px',
      'max-width': '1120px',
    },
    // "boxed" is an ordinary block: no layout capture even with layout props set.
    boxed: {
      color: 'rgb(10, 20, 30)',
      display: 'block',
      'justify-content': 'center',
      padding: '8px',
    },
  });

  assert.match(html, /display:flex/);
  assert.match(html, /flex-direction:column/);
  assert.match(html, /justify-content:space-between/);
  assert.match(html, /align-items:center/);
  assert.match(html, /gap:24px/);
  assert.match(html, /padding:16px 24px/);
  assert.match(html, /display:grid/);
  assert.match(html, /grid-template-columns:480px 480px/);
  assert.match(html, /max-width:1120px/);
  assert.equal(html.includes('display:block'), false);
  assert.equal(html.includes('justify-content:center'), false);
  assert.equal(html.includes('padding:8px'), false);
});

test('skips default-valued layout styles on containers', () => {
  const html = snapshotWithStyles({
    same: {
      display: 'flex',
      'flex-direction': 'row',
      'flex-wrap': 'nowrap',
      'justify-content': 'normal',
      'align-items': 'normal',
      gap: 'normal normal',
      'grid-template-columns': 'none',
      padding: '0px',
      margin: '0px',
      'max-width': 'none',
    },
  });

  assert.match(html, /display:flex/);
  assert.equal(html.includes('flex-direction'), false);
  assert.equal(html.includes('flex-wrap'), false);
  assert.equal(html.includes('justify-content'), false);
  assert.equal(html.includes('align-items'), false);
  assert.equal(html.includes('gap:'), false);
  assert.equal(html.includes('grid-template-columns'), false);
  assert.equal(html.includes('padding'), false);
  assert.equal(html.includes('margin:'), false);
  assert.equal(html.includes('max-width'), false);
});

test('strips named grid lines so the track sizes survive the server grammar', () => {
  // Computed grid-template-columns can include bracketed line names; the server
  // value grammar rejects brackets, so they must be stripped at capture or the
  // whole declaration (and the grid) would silently drop.
  const html = snapshotWithStyles({
    diff: {
      display: 'grid',
      'grid-template-columns': '[content-start] 480px [mid] 480px [content-end]',
    },
  });

  assert.match(html, /grid-template-columns:480px 480px/);
  // No bracketed line names may survive inside the captured declaration (the
  // snapshot legitimately contains "[masked]" placeholders elsewhere).
  assert.doesNotMatch(html, /grid-template-columns[^"]*\[/);
  assert.equal(html.includes('content-start'), false);
});

test('never captures layout styles on masked containers', () => {
  // A masked element that is also a flex container must stay fully unstyled.
  const html = snapshotWithStyles({
    'hidden-secret': {
      display: 'flex',
      'justify-content': 'space-between',
      gap: '12px',
    },
  });

  assert.equal(html.includes('justify-content:space-between'), false);
  assert.equal(html.includes('gap:12px'), false);
});

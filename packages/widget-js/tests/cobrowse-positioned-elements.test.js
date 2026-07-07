// Positioned-overlay capture for cobrowse snapshots (#540).
//
// Composition built on positioning instead of flex/grid — floating cards,
// overlays, badges — collapsed into normal flow in the replay. The widget now
// captures position:relative/absolute with px offsets and a bounded integer
// z-index. fixed and sticky stay uncaptured: page chrome remains in flow
// rather than pinning over the preview. jsdom has no layout, so computed
// styles are injected via options.view.

const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

function positionedSnapshot(bodyHtml, stylesByKey) {
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

test('captures a floating card: relative parent, absolute child with offsets and z-index', () => {
  const html = positionedSnapshot(
    '<section data-cs="hero"><div data-cs="copy">Copy</div><div data-cs="card">Card</div></section>',
    {
      hero: { position: 'relative' },
      card: { position: 'absolute', top: '48px', right: '40px', bottom: '32px', left: '531px', 'z-index': '1' },
    }
  );

  // The relative parent is captured even without offsets: it is the
  // containing block the absolute child anchors to.
  assert.match(html, /position:relative/);
  assert.match(html, /position:absolute;top:48px;right:40px;bottom:32px;left:531px;z-index:1/);
});

test('captures negative pixel offsets', () => {
  const html = positionedSnapshot(
    '<div data-cs="badge">B</div>',
    { badge: { position: 'absolute', top: '-12.5px', right: '-8px' } }
  );

  assert.match(html, /position:absolute;top:-12\.5px;right:-8px/);
});

test('never captures fixed or sticky positioning', () => {
  const html = positionedSnapshot(
    '<div data-cs="banner">Banner</div><div data-cs="nav">Nav</div>',
    {
      banner: { position: 'fixed', top: '0px', 'z-index': '100' },
      nav: { position: 'sticky', top: '0px' },
    }
  );

  assert.equal(html.includes('position:'), false);
  assert.equal(html.includes('top:0px'), false);
  assert.equal(html.includes('z-index'), false);
});

test('skips auto offsets, oversized offsets, and non-integer z-index', () => {
  const html = positionedSnapshot(
    '<div data-cs="loose">L</div>',
    { loose: { position: 'absolute', top: 'auto', left: '99999px', bottom: '20px', 'z-index': 'auto' } }
  );

  assert.match(html, /position:absolute;bottom:20px/);
  assert.equal(html.includes('top:'), false);
  assert.equal(html.includes('left:'), false);
  assert.equal(html.includes('99999px'), false);
  assert.equal(html.includes('z-index'), false);
});

test('never captures positioning for masked elements', () => {
  const html = positionedSnapshot(
    '<div data-cs="secret" data-secret>hidden</div>',
    { secret: { position: 'absolute', top: '10px', left: '10px' } }
  );

  assert.equal(html.includes('position:'), false);
});

test('suppresses absolute capture inside fixed or sticky chrome', () => {
  // The close button's containing block is the fixed banner, which is
  // intentionally left in flow — capturing the button's offsets would
  // re-anchor it to the wrong element and float it over the preview.
  const html = positionedSnapshot(
    '<div data-cs="banner"><button data-cs="close">×</button></div>',
    {
      banner: { position: 'fixed', top: '0px', 'z-index': '100' },
      close: { position: 'absolute', top: '8px', right: '8px' },
    }
  );

  assert.equal(html.includes('position:'), false);
  assert.equal(html.includes('top:8px'), false);
});

test('a relative wrapper below fixed chrome re-establishes capture', () => {
  // The relative panel is itself captured, so it is a faithful containing
  // block in the replay — its absolute descendants anchor correctly again.
  const html = positionedSnapshot(
    '<div data-cs="banner"><div data-cs="panel"><span data-cs="badge">1</span></div></div>',
    {
      banner: { position: 'fixed', top: '0px' },
      panel: { position: 'relative' },
      badge: { position: 'absolute', top: '4px', right: '4px' },
    }
  );

  assert.match(html, /position:relative/);
  assert.match(html, /position:absolute;top:4px;right:4px/);
  assert.equal(html.includes('position:fixed'), false);
});

// Sanitized inline SVG + sized image placeholders (#523).
//
// SVG is markup, not a network fetch, so it survives capture through a hard
// element/attribute allowlist (no script, foreignObject, image, animate,
// event handlers, or external hrefs; url(#...) paint refs only). Images are
// replaced with same-size placeholders so layout and alt text survive while
// no image URL or pixel data ever leaves the visitor page.

const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

function snapshotOf(bodyHtml) {
  const dom = new JSDOM(
    '<!doctype html><html><head><title>T</title></head><body>' + bodyHtml + '</body></html>',
    { url: 'https://host.example.test/page' },
  );

  return Wayfindr.createCobrowseSnapshot(dom.window.document, {
    location: dom.window.location,
  }).html;
}

test('captures a sanitized inline SVG logo', () => {
  const html = snapshotOf([
    '<svg viewBox="0 0 24 24" width="24" height="24" onload="alert(1)">',
    '  <defs><linearGradient id="brand"><stop offset="0" stop-color="rgb(13,111,104)"></stop></linearGradient></defs>',
    '  <path d="M4 4h16v16H4z" fill="url(#brand)"></path>',
    '  <circle cx="12" cy="12" r="4" fill="#0d6f68"></circle>',
    '  <use href="#brand"></use>',
    '  <script>steal()</script>',
    '  <foreignObject><div>smuggled</div></foreignObject>',
    '  <image href="https://evil.example/x.png"></image>',
    '</svg>',
  ].join(''));

  assert.match(html, /<svg[^>]*viewBox="0 0 24 24"/i);
  assert.match(html, /<path[^>]*d="M4 4h16v16H4z"/);
  assert.match(html, /fill="url\(#brand\)"/);
  assert.match(html, /stop-color="rgb\(13,111,104\)"/);
  assert.match(html, /<use[^>]*href="#brand"/);
  assert.equal(html.includes('onload'), false);
  assert.equal(html.includes('steal()'), false);
  assert.equal(html.includes('foreignObject'), false);
  assert.equal(html.includes('smuggled'), false);
  assert.equal(html.includes('<image'), false);
  assert.equal(html.includes('evil.example'), false);
});

test('drops external references and unsafe values inside SVG', () => {
  const html = snapshotOf([
    '<svg viewBox="0 0 10 10">',
    '  <path d="M0 0h10" fill="url(https://evil.example/paint)"></path>',
    '  <use href="https://evil.example/sprite.svg#icon"></use>',
    '  <rect width="4" height="4" fill="javascript:alert(1)"></rect>',
    '</svg>',
  ].join(''));

  assert.match(html, /<svg/);
  assert.equal(html.includes('evil.example'), false);
  assert.equal(html.includes('javascript:'), false);
  // The elements survive; only the offending attributes are stripped.
  assert.match(html, /<path[^>]*d="M0 0h10"/);
  assert.match(html, /<rect[^>]*width="4"/);
});

test('drops oversized SVGs entirely instead of partially', () => {
  const shapes = [];

  for (let i = 0; i < 220; i++) {
    shapes.push('<rect x="' + i + '" width="1" height="1"></rect>');
  }

  const html = snapshotOf('<p>Copy stays.</p><svg viewBox="0 0 10 10">' + shapes.join('') + '</svg>');

  assert.equal(html.includes('<svg'), false);
  assert.equal(html.includes('<rect'), false);
  assert.match(html, /Copy stays\./);
});

test('replaces images with same-size placeholders carrying alt text', () => {
  const html = snapshotOf(
    '<img src="https://cdn.example.test/product.png" alt="Blue running shoe" width="320" height="240">',
  );

  assert.equal(html.includes('<img'), false);
  assert.equal(html.includes('cdn.example.test'), false);
  assert.match(html, /<wayfindr-img-placeholder[^>]*style="width:320px;height:240px"/);
  assert.match(html, /Blue running shoe/);
});

test('masked images keep their placeholder but lose their alt text', () => {
  const html = snapshotOf(
    '<img src="https://cdn.example.test/id-scan.png" alt="Passport scan for Jane Doe" width="300" height="200" data-secret>',
  );

  assert.equal(html.includes('<img'), false);
  assert.equal(html.includes('Passport scan'), false);
  assert.equal(html.includes('Jane Doe'), false);
  assert.match(html, /<wayfindr-img-placeholder[^>]*data-secret/);
  assert.match(html, /\[masked\]/);
});

// Computed-style capture for added mutation subtrees (#539).
//
// SPA-inserted content arrives through the mutation stream, not a full
// snapshot. Before this, added subtrees carried no captured styles — they
// replayed structurally correct but unstyled until the next resync. The
// mutation batch now threads a computed-style view into the added-subtree
// clone, rooted at the added element and reading its real parent for the
// inherited baseline, under the same masking and position rules as the
// snapshot. jsdom has no layout, so computed styles are injected via a
// getComputedStyle stub keyed on data-cs.

const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

function styledView(dom, stylesByKey) {
  return {
    getComputedStyle(element) {
      const key = element.getAttribute ? element.getAttribute('data-cs') : null;
      const map = (key && stylesByKey[key]) || {};

      return { getPropertyValue: (property) => map[property] || '' };
    },
  };
}

function addedMutationHtml(dom, addedNode, target, stylesByKey) {
  const batch = Wayfindr.createCobrowseMutationBatch([
    { type: 'childList', target, addedNodes: [addedNode], removedNodes: [] },
  ], {
    document: dom.window.document,
    location: dom.window.location,
    view: styledView(dom, stylesByKey),
  });

  const added = batch.mutations.find((mutation) => mutation.type === 'added');

  return added ? added.html : '';
}

test('captures color, gradient, and layout on an added subtree', () => {
  const dom = new JSDOM('<!doctype html><html><body><main data-cs="main"></main></body></html>', {
    url: 'https://host.example.test/page',
  });
  const main = dom.window.document.querySelector('main');

  const card = dom.window.document.createElement('div');
  card.setAttribute('data-cs', 'card');
  const label = dom.window.document.createElement('span');
  label.setAttribute('data-cs', 'label');
  label.textContent = 'Loaded';
  card.appendChild(label);

  const html = addedMutationHtml(dom, card, main, {
    main: { color: 'rgb(10, 20, 30)', 'font-family': 'Arial' },
    card: {
      color: 'rgb(10, 20, 30)',
      'font-family': 'Arial',
      'background-image': 'linear-gradient(90deg, rgb(1, 2, 3), rgb(4, 5, 6))',
      display: 'flex',
      'justify-content': 'space-between',
    },
    label: { color: 'rgb(200, 0, 0)', 'font-family': 'Arial' },
  });

  // Own styles on the added root are captured.
  assert.match(html, /background-image:linear-gradient\(90deg, rgb\(1, 2, 3\), rgb\(4, 5, 6\)\)/);
  assert.match(html, /display:flex/);
  assert.match(html, /justify-content:space-between/);
  // The label's changed color emits; inherited values matching the real
  // parent (color:rgb(10,20,30), Arial) are not repeated on the added root.
  assert.match(html, /color:rgb\(200, 0, 0\)/);
  assert.equal((html.match(/color:rgb\(10, 20, 30\)/g) || []).length, 0);
});

test('suppresses absolute capture on added content inside fixed chrome', () => {
  const dom = new JSDOM(
    '<!doctype html><html><body><div data-cs="banner"><div data-cs="slot"></div></div></body></html>',
    { url: 'https://host.example.test/page' }
  );
  const slot = dom.window.document.querySelector('[data-cs="slot"]');

  const badge = dom.window.document.createElement('span');
  badge.setAttribute('data-cs', 'badge');
  badge.textContent = 'x';

  const html = addedMutationHtml(dom, badge, slot, {
    banner: { position: 'fixed', top: '0px' },
    slot: { position: 'static' },
    badge: { position: 'absolute', top: '4px', right: '4px' },
  });

  // The added badge's containing block is the dropped fixed banner, so its
  // absolute offsets must not be captured — same rule as a full snapshot.
  assert.equal(html.includes('position:'), false);
  assert.equal(html.includes('top:4px'), false);
});

test('never captures styles for an added masked subtree', () => {
  const dom = new JSDOM('<!doctype html><html><body><main data-cs="main"></main></body></html>', {
    url: 'https://host.example.test/page',
  });
  const main = dom.window.document.querySelector('main');

  const secretBox = dom.window.document.createElement('div');
  secretBox.setAttribute('data-cs', 'secret');
  secretBox.setAttribute('data-secret', '');
  secretBox.textContent = 'hidden';

  const html = addedMutationHtml(dom, secretBox, main, {
    main: {},
    secret: { 'background-color': 'rgb(9, 9, 9)', color: 'rgb(1, 1, 1)' },
  });

  assert.equal(html.includes('background-color:rgb(9, 9, 9)'), false);
  assert.match(html, /\[masked\]/);
});

test('added subtrees carry no styles when style capture is disabled', () => {
  const dom = new JSDOM('<!doctype html><html><body><main data-cs="main"></main></body></html>', {
    url: 'https://host.example.test/page',
  });
  const main = dom.window.document.querySelector('main');

  const card = dom.window.document.createElement('div');
  card.setAttribute('data-cs', 'card');
  card.textContent = 'Loaded';

  const batch = Wayfindr.createCobrowseMutationBatch([
    { type: 'childList', target: main, addedNodes: [card], removedNodes: [] },
  ], {
    document: dom.window.document,
    location: dom.window.location,
    captureStyles: false,
    view: styledView(dom, { card: { 'background-color': 'rgb(9, 9, 9)' } }),
  });

  const added = batch.mutations.find((mutation) => mutation.type === 'added');

  assert.equal(added.html.includes('background-color'), false);
});

test('top-level additions under body carry full inherited typography', () => {
  // The preview shell's body only receives background declarations, so an
  // element added directly under <body> must emit its own color/font like a
  // snapshot's body child — not suppress them as "same as the real body."
  const dom = new JSDOM('<!doctype html><html><body data-cs="body"></body></html>', {
    url: 'https://host.example.test/page',
  });
  const body = dom.window.document.body;

  const banner = dom.window.document.createElement('div');
  banner.setAttribute('data-cs', 'banner');
  banner.textContent = 'Notice';

  const html = addedMutationHtml(dom, banner, body, {
    body: { color: 'rgb(20, 20, 20)', 'font-family': 'Georgia' },
    banner: { color: 'rgb(20, 20, 20)', 'font-family': 'Georgia' },
  });

  assert.match(html, /color:rgb\(20, 20, 20\)/);
  assert.match(html, /font-family:Georgia/);
});

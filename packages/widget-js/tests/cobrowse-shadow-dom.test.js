// Shadow-DOM capture for cobrowse snapshots.
//
// cloneNode(true) does not include shadow roots, so web-component content was
// absent from the agent preview. Open shadow roots are now inlined and masked
// like light DOM; closed shadow roots stay inaccessible by design.
//
// See issue #493 (epic #490) and docs/privacy/cobrowse-data-boundaries.md.

const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

function documentWithBody(bodyHtml) {
  return new JSDOM(
    `<!doctype html><html><head><title>Fixture</title></head><body>${bodyHtml}</body></html>`,
    { url: 'https://host.example.test/page' },
  );
}

test('inlines open shadow DOM content into the snapshot', () => {
  const dom = documentWithBody('<div id="host"></div>');
  const doc = dom.window.document;
  const shadow = doc.querySelector('#host').attachShadow({ mode: 'open' });
  shadow.innerHTML = '<p>Shadow visible text</p>';

  const snapshot = Wayfindr.createCobrowseSnapshot(doc, { location: dom.window.location });

  assert.equal(snapshot.html.includes('Shadow visible text'), true);
  // Inlined shadow content is marked for provenance.
  assert.match(snapshot.html, /data-wayfindr-shadow-content/);
});

test('masks sensitive fields inside open shadow DOM before export', () => {
  const dom = documentWithBody('<div id="host"></div>');
  const doc = dom.window.document;
  const shadow = doc.querySelector('#host').attachShadow({ mode: 'open' });
  shadow.innerHTML = [
    '<p>Safe shadow copy.</p>',
    '<div aria-label="password">SHADOW-SECRET</div>',
    '<input name="ssn" value="SHADOW-SSN">',
  ].join('');

  const snapshot = Wayfindr.createCobrowseSnapshot(doc, { location: dom.window.location });

  assert.equal(snapshot.html.includes('Safe shadow copy.'), true);
  assert.equal(snapshot.html.includes('SHADOW-SECRET'), false);
  assert.equal(snapshot.html.includes('SHADOW-SSN'), false);
  assert.match(snapshot.html, /\[masked\]/);
});

test('does not capture closed shadow roots', () => {
  const dom = documentWithBody('<div id="host"></div>');
  const doc = dom.window.document;
  const shadow = doc.querySelector('#host').attachShadow({ mode: 'closed' });
  shadow.innerHTML = '<p>CLOSED-CONTENT</p>';

  const snapshot = Wayfindr.createCobrowseSnapshot(doc, { location: dom.window.location });

  // Closed shadow roots are inaccessible, so the content is simply absent.
  assert.equal(snapshot.html.includes('CLOSED-CONTENT'), false);
});

test('preserves template content when capturing (regression for deep clone)', () => {
  const dom = documentWithBody('<template id="t"><p>TEMPLATE-CONTENT</p></template>');
  const doc = dom.window.document;

  const snapshot = Wayfindr.createCobrowseSnapshot(doc, { location: dom.window.location });

  assert.equal(snapshot.html.includes('TEMPLATE-CONTENT'), true);
});

test('captures and masks shadow DOM in an added mutation subtree', () => {
  const dom = documentWithBody('<main></main>');
  const doc = dom.window.document;

  const added = doc.createElement('div');
  const addedShadow = added.attachShadow({ mode: 'open' });
  addedShadow.innerHTML = '<span>Added shadow visible</span><input aria-label="password" value="ADDED-SECRET">';

  const batch = Wayfindr.createCobrowseMutationBatch([
    {
      type: 'childList',
      target: doc.querySelector('main'),
      addedNodes: [added],
      removedNodes: [],
    },
  ], {
    document: doc,
    location: dom.window.location,
  });

  const mutation = batch.mutations[0];

  assert.equal(mutation.type, 'added');
  assert.equal(mutation.html.includes('Added shadow visible'), true);
  assert.equal(JSON.stringify(batch).includes('ADDED-SECRET'), false);
});

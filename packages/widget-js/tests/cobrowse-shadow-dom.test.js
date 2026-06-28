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
  // Inlined shadow content lives in a uniquely tagged wrapper for provenance.
  assert.match(snapshot.html, /<wayfindr-shadow-content/);
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

test('does not serialize inert template content (masking cannot reach it)', () => {
  // querySelectorAll-based masking does not descend into template.content, so
  // capturing it could leak unmasked markup. The inert, unrendered content is
  // dropped instead.
  const dom = documentWithBody('<template id="t"><input name="ssn" value="123-45-6789"><p>TEMPLATE-CONTENT</p></template>');
  const doc = dom.window.document;

  const snapshot = Wayfindr.createCobrowseSnapshot(doc, { location: dom.window.location });

  assert.equal(snapshot.html.includes('123-45-6789'), false);
  assert.equal(snapshot.html.includes('TEMPLATE-CONTENT'), false);
});

test('does not leak sensitive template content nested inside an open shadow root', () => {
  const dom = documentWithBody('<div id="host"></div>');
  const doc = dom.window.document;
  const shadow = doc.querySelector('#host').attachShadow({ mode: 'open' });
  shadow.innerHTML = '<p>Visible shadow copy.</p><template><input name="ssn" value="SHADOW-TEMPLATE-SSN"></template>';

  const snapshot = Wayfindr.createCobrowseSnapshot(doc, { location: dom.window.location });

  assert.equal(snapshot.html.includes('Visible shadow copy.'), true);
  assert.equal(snapshot.html.includes('SHADOW-TEMPLATE-SSN'), false);
});

test('shadow wrapper keeps light-DOM nth-of-type ordering stable', () => {
  // The host has light-DOM div children plus an open shadow root. The shadow
  // wrapper must not shift the light divs' nth-of-type indices, or replayed
  // mutation paths (computed from the real DOM) would resolve to the wrapper.
  const dom = documentWithBody('<div id="host"><div>light-one</div><div>light-two</div></div>');
  const doc = dom.window.document;
  const shadow = doc.querySelector('#host').attachShadow({ mode: 'open' });
  shadow.innerHTML = '<div>shadow-div</div>';

  const snapshot = Wayfindr.createCobrowseSnapshot(doc, { location: dom.window.location });

  const rendered = new JSDOM(`<!doctype html><html><body>${snapshot.html}</body></html>`).window.document;
  const host = rendered.querySelector('#host');
  const lightDivs = Array.prototype.filter.call(host.children, (el) => el.tagName.toLowerCase() === 'div');

  // The first/second real div children are still the light ones, in order.
  assert.equal(lightDivs[0].textContent, 'light-one');
  assert.equal(lightDivs[1].textContent, 'light-two');
  // Shadow content is present but isolated in the non-colliding wrapper tag.
  assert.equal(host.querySelector('wayfindr-shadow-content') !== null, true);
  assert.equal(snapshot.html.includes('shadow-div'), true);
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

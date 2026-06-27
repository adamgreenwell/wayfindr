// Adversarial masking matrix for cobrowse capture.
//
// Masking is the privacy promise the whole cobrowse feature rests on: no raw
// sensitive value should ever leave the visitor browser. These tests exercise
// that promise systematically across the snapshot path and all three mutation
// types, rather than the happy-path spot checks in wayfindr-widget.test.js.
//
// See issue #487 and docs/privacy/cobrowse-data-boundaries.md.

const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

// Mirrors SENSITIVE_FIELD_TERMS in src/wayfindr-widget.js. Kept as a literal
// list (not imported) so a silent edit to the source list trips this test.
const SENSITIVE_FIELD_TERMS = [
  'password', 'passwd', 'pwd', 'passcode', 'secret', 'token', 'api key',
  'apikey', 'auth', 'authorization', 'one time code', 'otp', 'ssn',
  'social security', 'tax id', 'ein', 'sin', 'national id', 'credit card',
  'card number', 'cardnumber', 'cc number', 'ccnumber', 'cvc', 'cvv',
  'security code', 'expiration', 'expiry', 'routing number', 'account number',
  'bank account', 'iban', 'sort code', 'username', 'user name', 'login',
  'email', 'e mail', 'phone', 'telephone', 'address', 'postal code', 'zip',
  'birthdate', 'date of birth', 'dob',
];

// Mirrors SENSITIVE_FIELD_ATTRIBUTES in src/wayfindr-widget.js.
const SENSITIVE_FIELD_ATTRIBUTES = [
  'id', 'name', 'autocomplete', 'aria-label', 'placeholder', 'data-field',
  'data-wayfindr-field', 'data-testid', 'data-test', 'data-cy',
];

function documentFromBody(bodyHtml, url) {
  return new JSDOM(
    `<!doctype html><html><head><title>Fixture</title></head><body>${bodyHtml}</body></html>`,
    { url: url || 'https://host.example.test/page' },
  );
}

function snapshotFromBody(bodyHtml, options) {
  const dom = documentFromBody(bodyHtml);
  const snapshot = Wayfindr.createCobrowseSnapshot(dom.window.document, Object.assign({
    location: dom.window.location,
  }, options || {}));

  return { dom, snapshot };
}

test('masks every inferred sensitive term carried in an element attribute', () => {
  SENSITIVE_FIELD_TERMS.forEach((term, index) => {
    const sentinel = `TERMSENTINEL${index}DEADBEEF`;
    const { snapshot } = snapshotFromBody(`<div aria-label="${term}">${sentinel}</div>`);

    assert.equal(
      snapshot.html.includes(sentinel),
      false,
      `sensitive term "${term}" should hide the element value`,
    );
    assert.match(
      snapshot.html,
      /\[masked\]/,
      `sensitive term "${term}" should produce a masked marker`,
    );
  });
});

test('infers sensitivity from every supported field attribute', () => {
  SENSITIVE_FIELD_ATTRIBUTES.forEach((attribute) => {
    const sentinel = `ATTRSENTINEL_${attribute.replace(/[^a-z]/gi, '')}`;
    const { snapshot } = snapshotFromBody(
      `<input ${attribute}="account password" value="${sentinel}">`,
    );

    assert.equal(
      snapshot.html.includes(sentinel),
      false,
      `attribute "${attribute}" should mark the field sensitive`,
    );
  });
});

test('masks form controls inferred from associated label text', () => {
  const variants = [
    // Wrapping label.
    '<label>Password <input value="WRAPSENTINEL"></label>',
    // Explicit for/id association.
    '<label for="pw">Password</label><input id="pw" value="FORIDSENTINEL">',
    // camelCase attribute normalization ("userPassword" -> "user password").
    '<input name="userPassword" value="CAMELSENTINEL">',
  ];
  const sentinels = ['WRAPSENTINEL', 'FORIDSENTINEL', 'CAMELSENTINEL'];

  variants.forEach((markup, index) => {
    const { snapshot } = snapshotFromBody(markup);

    assert.equal(
      snapshot.html.includes(sentinels[index]),
      false,
      `label variant ${index} should mask the field value`,
    );
  });
});

test('masks explicit default selectors before export', () => {
  const { snapshot } = snapshotFromBody([
    '<input type="password" value="PWSENTINEL">',
    '<input type="hidden" value="HIDDENSENTINEL">',
    '<div data-wayfindr-mask>MASKSENTINEL</div>',
    '<div data-wayfindr-private>PRIVATESENTINEL</div>',
    '<div data-secret>SECRETSENTINEL</div>',
  ].join(''));

  ['PWSENTINEL', 'HIDDENSENTINEL', 'MASKSENTINEL', 'PRIVATESENTINEL', 'SECRETSENTINEL']
    .forEach((sentinel) => {
      assert.equal(snapshot.html.includes(sentinel), false, `${sentinel} should be masked`);
    });

  assert.equal(snapshot.maskedCount, 5);
});

test('honors operator-provided site-level mask selectors', () => {
  const withoutSelector = snapshotFromBody('<div class="ssn-block">OPERATORSENTINEL</div>').snapshot;
  const withSelector = snapshotFromBody('<div class="ssn-block">OPERATORSENTINEL</div>', {
    maskSelectors: ['.ssn-block'],
  }).snapshot;

  // Class names are not inferred, so the value only disappears once the operator
  // adds the selector. This proves the operator control actually does the work.
  assert.equal(withoutSelector.html.includes('OPERATORSENTINEL'), true);
  assert.equal(withSelector.html.includes('OPERATORSENTINEL'), false);
});

test('clears non-sensitive form values even when they are not masked', () => {
  const { snapshot } = snapshotFromBody([
    '<form>',
    '  <input id="q" name="q" value="SEARCHSENTINEL">',
    '  <textarea name="comment">TEXTAREASENTINEL</textarea>',
    '</form>',
  ].join(''));

  assert.equal(snapshot.html.includes('SEARCHSENTINEL'), false);
  assert.equal(snapshot.html.includes('TEXTAREASENTINEL'), false);
  // These were cleared, not masked, so the masked counter stays at zero.
  assert.equal(snapshot.maskedCount, 0);
});

test('respects data-wayfindr-allow as a deliberate false-positive escape hatch', () => {
  const { snapshot } = snapshotFromBody(
    '<p data-wayfindr-allow aria-label="email">Contact email shown on purpose.</p>',
  );

  assert.match(snapshot.html, /Contact email shown on purpose/);
  assert.equal(snapshot.maskedCount, 0);
});

test('no raw sensitive value survives any cobrowse export path', () => {
  const secret = 'TOPSECRET_d34db33f';
  const dom = documentFromBody([
    '<main>',
    `  <div id="inferred" aria-label="password">${secret}</div>`,
    `  <input id="ssn" name="ssn" value="${secret}">`,
    '</main>',
  ].join(''));
  const doc = dom.window.document;

  // Snapshot path.
  const snapshot = Wayfindr.createCobrowseSnapshot(doc, { location: dom.window.location });
  assert.equal(JSON.stringify(snapshot).includes(secret), false, 'snapshot leaked a raw value');

  // Mutation path: text on a sensitive element, an unsafe attribute change, and
  // an added sensitive subtree should all be masked or dropped.
  const inferred = doc.querySelector('#inferred');
  const ssn = doc.querySelector('#ssn');
  inferred.textContent = secret;
  ssn.setAttribute('value', secret);

  const addedSensitive = doc.createElement('div');
  addedSensitive.setAttribute('data-field', 'credit card');
  addedSensitive.textContent = secret;

  const batch = Wayfindr.createCobrowseMutationBatch([
    { type: 'characterData', target: inferred.firstChild },
    { type: 'attributes', target: ssn, attributeName: 'value' },
    {
      type: 'childList',
      target: doc.querySelector('main'),
      addedNodes: [addedSensitive],
      removedNodes: [],
    },
  ], {
    document: doc,
    location: dom.window.location,
  });

  assert.equal(JSON.stringify(batch).includes(secret), false, 'mutation batch leaked a raw value');
});

// Operator-extensible / internationalized sensitive-field inference.
//
// The built-in sensitive-term list is English. Operators can configure
// site-level mask terms (returned in the widget bootstrap payload) to extend
// inference for their own language or domain without modifying the widget
// source. These tests cover the term-threading and the client-side parsing.
//
// See issue #489 and docs/privacy/cobrowse-data-boundaries.md.

const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

function documentFromBody(bodyHtml) {
  return new JSDOM(
    `<!doctype html><html><head><title>Fixture</title></head><body>${bodyHtml}</body></html>`,
    { url: 'https://host.example.test/page' },
  );
}

function jsonResponse(status, payload) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => payload,
  };
}

function memoryStorage() {
  const store = {};

  return {
    getItem: (key) => (Object.prototype.hasOwnProperty.call(store, key) ? store[key] : null),
    setItem: (key, value) => { store[key] = String(value); },
    removeItem: (key) => { delete store[key]; },
  };
}

test('snapshot masks fields matching operator-provided sensitive terms', () => {
  const body = '<div data-field="NHS Number">PATIENT-123</div>';

  // "nhs number" is not a built-in term, so the value is visible by default...
  const withoutTerm = Wayfindr.createCobrowseSnapshot(documentFromBody(body).window.document, {});
  assert.equal(withoutTerm.html.includes('PATIENT-123'), true);

  // ...and masked once the operator adds the term for this site.
  const withTerm = Wayfindr.createCobrowseSnapshot(documentFromBody(body).window.document, {
    sensitiveTerms: ['nhs number'],
  });
  assert.equal(withTerm.html.includes('PATIENT-123'), false);
  assert.match(withTerm.html, /\[masked\]/);
});

test('mutations mask content matching operator-provided sensitive terms', () => {
  const dom = documentFromBody('<main><span data-field="NHS Number">old</span></main>');
  const doc = dom.window.document;
  const span = doc.querySelector('span');
  span.textContent = 'PATIENT-999';

  const batch = Wayfindr.createCobrowseMutationBatch([
    { type: 'characterData', target: span.firstChild },
  ], {
    document: doc,
    location: dom.window.location,
    sensitiveTerms: ['nhs number'],
  });

  assert.equal(batch.mutations[0].text, '[masked]');
  assert.equal(JSON.stringify(batch).includes('PATIENT-999'), false);
});

test('client parses site mask_terms into normalized sensitive terms on bootstrap', async () => {
  const client = Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000/',
    sitePublicKey: 'site_public_docs',
    anonymousId: 'anon-1',
    storage: memoryStorage(),
    fetch: async () => jsonResponse(200, {
      data: {
        site: {
          public_key: 'site_public_docs',
          settings: {
            mask_terms: ['Contraseña', '  NHS Number  ', 'contraseña', 123, ''],
          },
        },
        visitor: { anonymous_id: 'anon-1', token: 'visitor-token-1' },
      },
    }),
  });

  assert.deepEqual(client.getSensitiveTerms(), []);

  await client.bootstrap('https://host.example.test/page');

  // Terms are normalized, de-duplicated, and non-strings/empties dropped.
  assert.deepEqual(client.getSensitiveTerms(), ['contrase a', 'nhs number']);
});

test('snapshot inference is unaffected when no custom terms are configured', () => {
  // A built-in term still masks; an unrelated custom-only field stays visible.
  const snapshot = Wayfindr.createCobrowseSnapshot(
    documentFromBody('<div aria-label="password">SECRET-1</div><div data-field="favorite color">blue</div>').window.document,
    {},
  );

  assert.equal(snapshot.html.includes('SECRET-1'), false);
  assert.equal(snapshot.html.includes('blue'), true);
});

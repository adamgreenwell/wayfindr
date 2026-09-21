const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

// A page gets ONE widget per site.
//
// There are two supported ways in -- the script tag auto-initialises from its
// `data-wayfindr-*` attributes, and a host can call `Wayfindr.init()`. Both are
// legitimate and nothing stopped both running, so a page whose snippet carries
// the attributes AND whose bundle calls init got two launchers, two appearance
// requests, and two clients racing on one set of per-site storage keys.
// wayfindr.cc did exactly that on every page load.

function memoryStorage() {
  const values = new Map();

  return {
    getItem: (key) => (values.has(key) ? values.get(key) : null),
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
  };
}

function page() {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://host.example.test/',
  });

  return dom;
}

function initOn(dom, options = {}) {
  return Wayfindr.init(Object.assign({
    document: dom.window.document,
    location: dom.window.location,
    navigator: dom.window.navigator,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_single',
    storage: memoryStorage(),
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    sessionRefreshMs: 0,
    fetch: async () => ({ ok: true, status: 200, json: async () => ({ data: {} }) }),
  }, options));
}

function roots(dom) {
  return dom.window.document.querySelectorAll('.wayfindr-widget').length;
}

test('a second init for the same site does not build a second widget', async () => {
  const dom = page();

  const first = initOn(dom);
  assert.equal(roots(dom), 1);

  const second = initOn(dom);

  // The defect: two roots, two launchers, two clients on one storage.
  assert.equal(roots(dom), 1, 'a second init must not add another widget');
  assert.equal(second, first, 'the existing widget is returned');
});

test('the ignored init says so, because its options are being dropped', async () => {
  const dom = page();
  const warnings = [];
  const realWarn = console.warn;

  console.warn = (...args) => warnings.push(args.join(' '));

  try {
    initOn(dom);
    initOn(dom, { launcherLabel: 'Ignored label' });
  } finally {
    console.warn = realWarn;
  }

  // Silently returning the running widget would leave a host watching their
  // settings do nothing, with no way to find out why -- auto-init is deferred
  // by a timer, so they cannot win the race by calling init() earlier.
  // Asserted on what the notice has to ACHIEVE, not its exact wording: it must
  // say the call was ignored and name the attribute responsible, so a host can
  // act on it. Pinning the sentence would make rewording it a test failure.
  assert.equal(warnings.length, 1, 'expected exactly one notice');
  assert.match(warnings[0], /ignored/i, 'says the call did nothing');
  assert.match(warnings[0], /data-wayfindr-site-key/, 'names the attribute that causes it');
});

test('a different site on the same page still gets its own widget', async () => {
  const dom = page();

  initOn(dom);
  initOn(dom, { sitePublicKey: 'site_public_other' });

  // Keyed by site, not global. A page may legitimately host two different
  // sites' widgets, and a global guard would silently break that.
  assert.equal(roots(dom), 2, 'a second SITE is not a duplicate');
});

test('destroying a widget lets the site be initialised again', async () => {
  const dom = page();

  const first = initOn(dom);
  first.destroy();

  assert.equal(roots(dom), 0, 'destroy removes the chrome');

  const second = initOn(dom);

  assert.equal(roots(dom), 1);
  assert.notEqual(second, first, 'a fresh widget, not the destroyed one');
});

test('a host that removes the node itself gets a fresh widget', async () => {
  const dom = page();

  const first = initOn(dom);

  // No destroy() -- just torn out of the DOM, which a host framework doing its
  // own cleanup will do. Registered state must not outlive what it describes,
  // or the next init hands back a widget whose chrome is gone and the page
  // ends up with no launcher at all.
  first.root.remove();

  const second = initOn(dom);

  assert.equal(roots(dom), 1, 'a widget was rebuilt');
  assert.notEqual(second, first, 'not the stale handle');
});

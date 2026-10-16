const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const test = require('node:test');
const root = path.resolve(__dirname, '../../../..');
const { JSDOM } = createRequire(path.join(root, 'packages/widget-js/package.json'))('jsdom');
const source = fs.readFileSync(path.join(root, 'apps/server/public/js/password-reset-fragment.js'), 'utf8');
const token = 'a'.repeat(64);
const valid = `#v=1&token=${token}&email=owner%2Bsupport%40example.test`;

function page(t, options = {}) {
  const dom = new JSDOM(`<p id="reset-status" role="status"></p>
    <form data-password-reset-fragment action="${options.action || '/reset-password'}" method="POST">
      <input name="_token" type="hidden" value="known-csrf">
      <input name="email" type="email" required disabled>
      <input name="password" type="password" required disabled>
      <input name="password_confirmation" type="password" required disabled>
      <button type="submit" disabled>Set new password</button>
    </form><a data-reset-sign-in href="/login" hidden>Sign in</a>`, {
    url: options.url || `https://tenant.example.test/reset-password${options.hash === undefined ? valid : options.hash}`,
    runScripts: 'outside-only',
  });
  t.after(() => dom.window.close());
  const { window } = dom;
  const calls = [];
  const events = [];
  const replace = window.history.replaceState.bind(window.history);
  window.history.replaceState = (...args) => { events.push('clear'); return replace(...args); };
  for (const name of ['localStorage', 'sessionStorage']) {
    Object.defineProperty(window, name, { get() { throw new Error('No credential storage access is permitted'); } });
  }
  for (const name of ['log', 'error', 'warn', 'debug', 'info']) {
    window.console[name] = () => { throw new Error('No console output is permitted'); };
  }
  window.TextDecoder = TextDecoder;
  window.AbortController = AbortController;
  window.fetch = async (url, settings) => {
    events.push('post');
    calls.push({ url, settings, address: window.location.href });
    if (options.fetch) return options.fetch(url, settings, calls.length);
    return new Response(JSON.stringify({ status: 'password_reset' }), { headers: { 'Content-Type': 'application/json' } });
  };
  if (options.before) options.before(window);
  window.eval(source);
  const form = window.document.querySelector('form');
  function submit() {
    form.elements.password.value = 'customer-chosen-password';
    form.elements.password_confirmation.value = 'customer-chosen-password';
    form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
  }
  return { window, form, calls, events, submit, status: window.document.getElementById('reset-status') };
}

async function settle() {
  for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve));
}

test('the fragment is cleared before the only deliberate POST, with credentials in the body only', async t => {
  const p = page(t);
  assert.equal(p.window.location.href, 'https://tenant.example.test/reset-password');
  assert.equal(p.form.elements.email.value, 'owner+support@example.test');
  assert.equal(p.window.document.documentElement.outerHTML.includes(token), false);
  assert.equal(p.calls.length, 0);
  p.submit();
  p.submit();
  await settle();
  assert.deepEqual(p.events, ['clear', 'post']);
  assert.equal(p.calls.length, 1);
  const { url, settings, address } = p.calls[0];
  assert.equal(url, 'https://tenant.example.test/reset-password');
  assert.equal(address, url);
  assert.equal(settings.method, 'POST');
  assert.equal(settings.credentials, 'same-origin');
  assert.equal(settings.mode, 'same-origin');
  assert.equal(settings.redirect, 'error');
  assert.equal(settings.referrerPolicy, 'no-referrer');
  assert.equal(settings.cache, 'no-store');
  assert.equal(settings.headers['X-CSRF-TOKEN'], 'known-csrf');
  assert.deepEqual(JSON.parse(settings.body), {
    token, email: 'owner+support@example.test', password: 'customer-chosen-password', password_confirmation: 'customer-chosen-password',
  });
  assert.match(p.status.textContent, /has been reset/);
  assert.equal(p.window.document.querySelector('[data-reset-sign-in]').hidden, false);
  assert.equal(p.form.elements.password.value, '');
  p.submit();
  await settle();
  assert.equal(p.calls.length, 1, 'success drops the in-memory credential');
});

test('closed fragment grammar rejects malformed, duplicate and unknown fields without requests or reflection', async t => {
  for (const hash of [
    '', '#token=' + token, valid + '&extra=1', valid + '&email=other%40example.test',
    valid.replace('v=1', 'v=2'), valid.replace('v=1', 'v=%31'), valid.replace('token=', 'token=x'),
    valid.replace(token, token.toUpperCase()), valid.replace('%40', '%4'), valid.replace('%40', '%GG'),
    valid.replace('%40', '@'), valid.replace('%2B', '%2b'), valid.replace('owner%2Bsupport', 'owner%0Asupport'),
    valid.replace('email=owner%2Bsupport%40example.test', 'email=not-an-address'),
    '#token=' + token + '&v=1&email=owner%40example.test',
  ]) {
    const p = page(t, { hash });
    assert.equal(p.window.location.hash, '');
    assert.equal(p.form.querySelector('button').disabled, true, hash);
    assert.match(p.status.textContent, /incomplete or invalid/);
    assert.equal(p.status.textContent.includes(token), false);
    p.submit();
    await settle();
    assert.equal(p.calls.length, 0);
  }
});

test('query/path fallback, foreign action, unavailable history and unsupported transport refuse', async t => {
  for (const options of [
    { url: `https://tenant.example.test/reset-password?token=${token}&email=owner%40example.test${valid}` },
    { url: `https://tenant.example.test/reset-password/placeholder${valid}` },
    { action: 'https://elsewhere.example.test/reset-password' },
    { action: '/reset-password?token=' + token },
    { before(w) { w.history.replaceState = () => { throw new Error(); }; } },
    { before(w) { w.fetch = undefined; } },
  ]) {
    const p = page(t, options);
    p.submit();
    await settle();
    assert.equal(p.calls.length, 0);
    assert.equal(p.form.querySelector('button').disabled, true);
  }
});

test('an already authenticated browser clears history and refuses without a redirect or POST', async t => {
  const p = page(t, { before(w) { w.document.querySelector('form').setAttribute('data-reset-authenticated', ''); } });
  assert.equal(p.window.location.href, 'https://tenant.example.test/reset-password');
  assert.deepEqual(p.events, ['clear']);
  assert.match(p.status.textContent, /already signed in/);
  assert.equal(p.form.querySelector('button').disabled, true);
  assert.equal(p.window.document.documentElement.outerHTML.includes(token), false);
  p.submit();
  await settle();
  assert.equal(p.calls.length, 0);
});

test('a known JSON422 allows deliberate correction in memory without reflecting response strings', async t => {
  const p = page(t, { fetch: (url, settings, count) => new Response(JSON.stringify(count === 1
    ? { message: token, errors: { password: [`<img src=x>${token}`] } }
    : { status: 'password_reset' }), {
    status: count === 1 ? 422 : 200, headers: { 'Content-Type': 'application/json' },
  }) });
  p.submit();
  await settle();
  assert.equal(p.calls.length, 1);
  assert.match(p.status.textContent, /Check your email/);
  assert.equal(p.status.textContent.includes(token), false);
  assert.equal(p.window.document.querySelector('img'), null);
  assert.equal(p.form.querySelector('button').disabled, false);
  assert.equal(p.form.elements.password.value, '');
  p.submit();
  await settle();
  assert.equal(p.calls.length, 2);
  assert.equal(JSON.parse(p.calls[1].settings.body).token, token);
  assert.match(p.status.textContent, /has been reset/);
});

test('redirects, malformed success, server errors and network ambiguity never auto retry', async t => {
  const cases = [
    () => new Response('', { status: 302, headers: { Location: '/login' } }),
    () => new Response('<html>Login</html>', { headers: { 'Content-Type': 'text/html' } }),
    () => new Response('{broken', { headers: { 'Content-Type': 'application/json' } }),
    () => new Response(JSON.stringify({ status: 'password_reset', extra: true }), { headers: { 'Content-Type': 'application/json' } }),
    () => new Response(JSON.stringify({ status: 'not_completed' }), { headers: { 'Content-Type': 'application/json' } }),
    () => new Response('{}', { status: 500, headers: { 'Content-Type': 'application/json' } }),
    () => new Response('{}', { status: 429, headers: { 'Content-Type': 'application/json' } }),
    () => new Response(JSON.stringify({ message: '', errors: { unexpected: ['error'] } }), { status: 422, headers: { 'Content-Type': 'application/json' } }),
    () => new Response(' '.repeat(16385), { headers: { 'Content-Type': 'application/json' } }),
    () => { throw new Error('network request outcome unknown'); },
    () => { const r = new Response('{"status":"password_reset"}', { headers: { 'Content-Type': 'application/json' } }); Object.defineProperty(r, 'redirected', { value: true }); return r; },
  ];
  for (const fetch of cases) {
    const p = page(t, { fetch });
    p.submit();
    await settle();
    assert.match(p.status.textContent, /Do not resubmit/);
    assert.equal(p.form.querySelector('button').disabled, true);
    assert.equal(p.window.document.querySelector('[data-reset-sign-in]').hidden, true);
    p.submit();
    await settle();
    assert.equal(p.calls.length, 1);
  }
});

test('an expired CSRF form invalidates its credential instead of replaying', async t => {
  const p = page(t, { fetch: () => new Response('', { status: 419 }) });
  p.submit();
  await settle();
  assert.match(p.status.textContent, /form has expired/);
  assert.equal(p.form.querySelector('button').disabled, true);
  p.submit();
  await settle();
  assert.equal(p.calls.length, 1);
});

test('the finite deadline aborts an unknown transport and disables resubmission', async t => {
  let abort;
  const p = page(t, {
    before(w) { w.setTimeout = callback => { abort = callback; return 1; }; w.clearTimeout = () => {}; },
    fetch: (url, settings) => new Promise((resolve, reject) => settings.signal.addEventListener('abort', () => reject(new Error('timeout')))),
  });
  p.submit();
  abort();
  await settle();
  assert.equal(p.calls[0].settings.signal.aborted, true);
  assert.match(p.status.textContent, /Do not resubmit/);
  p.submit();
  await settle();
  assert.equal(p.calls.length, 1);
});

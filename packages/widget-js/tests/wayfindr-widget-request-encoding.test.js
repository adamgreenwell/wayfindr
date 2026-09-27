const assert = require('node:assert/strict');
const test = require('node:test');

const Wayfindr = require('../src/wayfindr-widget.js');

// JSON.stringify writes half a surrogate pair as a "\udXXX" escape. A browser
// reads that back happily; PHP's decoder refuses the WHOLE request for it, and
// the server used to answer the empty request it was left with as "Site not
// found." -- on every Retry, since the same bytes went out each time. These
// read the raw bytes the widget sends, because a fake that JSON.parse-s them
// accepts exactly what the server refuses.

function encodingClient(sent, answer) {
  return Wayfindr.createClient({
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_encoding',
    anonymousId: 'anon-encoding',
    visitorToken: 'visitor-token-encoding',
    fetch: async (url, init) => {
      sent.push(init && typeof init.body === 'string' ? init.body : null);

      if (answer) {
        return answer();
      }

      return {
        ok: true,
        status: 201,
        json: async () => ({ data: { conversation: { support_code: 'WF-ENC', status: 'open' }, message: { id: 1 } } }),
      };
    },
  });
}

test('half an emoji is sent as a replacement character, not as an escape the server refuses', async () => {
  const sent = [];

  await encodingClient(sent).sendMessage('WF-ENC', 'hi \ud83d there');

  // JSON.stringify writes a whole emoji as itself and a lone half as a
  // "\udXXX" escape, so any surrogate escape in the bytes is a lone half.
  assert.doesNotMatch(sent[0], /\\ud[89a-f][0-9a-f]{2}/i, `the lone half was sent as an escape PHP refuses: ${sent[0]}`);
  assert.equal(JSON.parse(sent[0]).body, 'hi � there');
});

test('a whole emoji is sent untouched', async () => {
  const sent = [];

  await encodingClient(sent).sendMessage('WF-ENC', 'thanks 😀');

  assert.equal(JSON.parse(sent[0]).body, 'thanks 😀', 'a valid surrogate pair was altered');
});

test('browsers without toWellFormed get the same repair', async (t) => {
  // String.prototype.toWellFormed is ES2024. Older browsers take the fallback,
  // which must keep whole pairs and replace only a lone half.
  const native = String.prototype.toWellFormed;
  delete String.prototype.toWellFormed;
  t.after(() => {
    String.prototype.toWellFormed = native;
  });

  const sent = [];

  await encodingClient(sent).sendMessage('WF-ENC', '\udc00 lone low, 😀 whole, lone high \ud83d');

  assert.doesNotMatch(sent[0], /\\ud[89a-f][0-9a-f]{2}/i, `the fallback sent a lone half as an escape PHP refuses: ${sent[0]}`);
  assert.equal(
    JSON.parse(sent[0]).body,
    '� lone low, 😀 whole, lone high �',
    'the fallback did not replace exactly the lone halves',
  );
});

test('the server’s unreadable-request refusal reaches the caller as a key the widget can say', async () => {
  const sent = [];
  const client = encodingClient(sent, () => ({
    ok: false,
    status: 400,
    json: async () => ({ message: 'The request could not be read.', error_key: 'error.unreadableRequest' }),
  }));

  let thrown = null;

  try {
    await client.sendMessage('WF-ENC', 'hello');
  } catch (error) {
    thrown = error;
  }

  assert.ok(thrown, 'a 400 was not raised as an error');
  assert.equal(thrown.wayfindrKey, 'error.unreadableRequest', 'the widget does not carry the key the server answers with, so it cannot translate it');
});

test('a host’s context keys are made well formed too, at every depth', async () => {
  // A replacer can only change values, and visitorContext's KEYS are the
  // host's to choose. One lone half in a key refused the whole bootstrap.
  const sent = [];
  const client = encodingClient(sent, () => ({
    ok: true,
    status: 200,
    json: async () => ({
      data: {
        site: { public_key: 'site_public_encoding', settings: {} },
        visitor: { anonymous_id: 'anon-encoding', token: 'visitor-token-encoding' },
      },
    }),
  }));

  await client.bootstrap('https://shop.example.test/', {
    ['plan\ud83d']: 'Team',
    nested: { ['region\udc00']: 'EU \ud83d', list: ['a\ud83d', 'whole 😀'] },
  });

  assert.doesNotMatch(sent[0], /\\ud[89a-f][0-9a-f]{2}/i, `a context key was sent as an escape PHP refuses: ${sent[0]}`);

  const context = JSON.parse(sent[0]).context;

  assert.deepEqual(context, {
    'plan�': 'Team',
    nested: { 'region�': 'EU �', list: ['a�', 'whole 😀'] },
  });
});

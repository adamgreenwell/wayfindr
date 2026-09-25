const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

// A proactive opener answers under the site's name, exactly as an API
// integration does, so to the visitor a canned invitation read like a reply
// somebody had typed for them. The server now marks the opener `automated` and
// the widget says so beside the name. An integration is left unmarked: it may
// be relaying a person.

function jsonResponse(status, payload) {
  return { ok: status >= 200 && status < 300, status, json: async () => payload };
}

async function settle() {
  for (let i = 0; i < 4; i++) {
    await new Promise((resolve) => setImmediate(resolve));
  }
}

function memoryStorage(seed) {
  const values = new Map(Object.entries(seed || {}));

  return {
    getItem: (key) => (values.has(key) ? values.get(key) : null),
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
  };
}

function minutesAgo(minutes) {
  return new Date(Date.now() - minutes * 60 * 1000).toISOString();
}

// The opener, then an integration post under the SAME name inside the
// grouping window, then a person. The middle one is the case that matters:
// it looks like the opener in every way except the flag.
function automatedSenderTranscript() {
  return [
    { id: 1, sender: { kind: 'agent', name: 'Shop support', automated: true }, type: 'text', body: 'Questions about plans?', attachments: [], created_at: minutesAgo(3) },
    { id: 2, sender: { kind: 'agent', name: 'Shop support' }, type: 'text', body: 'Your order has shipped.', attachments: [], created_at: minutesAgo(2) },
    { id: 3, sender: { kind: 'agent', name: 'Ada Agent' }, type: 'text', body: 'Anything else I can help with?', attachments: [], created_at: minutesAgo(1) },
  ];
}

function automatedSenderWidget({ browser, transcript } = {}) {
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://shop.example.test/',
  });

  return Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    navigator: { languages: browser ? [browser] : [] },
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_automated',
    storage: memoryStorage({
      'wayfindr:site_public_automated:anonymous-id': 'anon-automated',
      'wayfindr:site_public_automated:visitor-token': 'visitor-token-automated',
      'wayfindr:site_public_automated:support-code': 'WF-AUTO',
    }),
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    presencePollMs: 0,
    fetch: async (url) => {
      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: {
            site: { public_key: 'site_public_automated', settings: {} },
            visitor: { anonymous_id: 'anon-automated', token: 'visitor-token-automated' },
          },
        });
      }

      if (url.includes('/cobrowse')) {
        return jsonResponse(200, { data: { cobrowse: { state: 'unavailable' } } });
      }

      if (url.includes('/messages')) {
        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-AUTO', status: 'open' },
            messages: (transcript || automatedSenderTranscript)(),
          },
        });
      }

      return jsonResponse(200, { data: {} });
    },
  });
}

function automatedSenderRendered(widget) {
  return Array.from(widget.root.querySelectorAll('.wayfindr-widget__message')).map((item) => {
    const tag = item.querySelector('.wayfindr-widget__message-automated');

    return {
      body: item.querySelector('.wayfindr-widget__message-body').textContent,
      tag: tag ? tag.textContent : null,
      grouped: item.classList.contains('wayfindr-widget__message--grouped'),
    };
  });
}

test('the proactive opener is tagged automated and no other sender is', async (t) => {
  const widget = automatedSenderWidget();
  t.after(() => widget.destroy());

  await widget.open();
  await settle();

  const rendered = automatedSenderRendered(widget);

  assert.deepEqual(
    rendered.map((item) => item.body),
    ['Questions about plans?', 'Your order has shipped.', 'Anything else I can help with?'],
    'the transcript did not render, so nothing below proves anything',
  );
  assert.equal(rendered[0].tag, 'Automated', 'the proactive opener reads like a reply somebody typed');
  assert.equal(rendered[1].tag, null, 'an integration post was called automated, but it may be relaying a person');
  assert.equal(rendered[2].tag, null, 'a person on the desk was called automated');
});

test('an unflagged post under the same name does not group beneath the automated tag', async (t) => {
  // Grouped, a message hides its name line and reads as a continuation of the
  // one above -- so the integration post would sit under the opener's tag,
  // looking automated without being called it.
  const widget = automatedSenderWidget();
  t.after(() => widget.destroy());

  await widget.open();
  await settle();

  const rendered = automatedSenderRendered(widget);

  assert.equal(rendered.length, 3, 'the transcript did not render, so nothing below proves anything');
  assert.equal(rendered[1].grouped, false, 'the integration post was grouped under the automated opener');
});

test('the tag is said in the language the panel is speaking', async (t) => {
  const widget = automatedSenderWidget({ browser: 'de-DE' });
  t.after(() => widget.destroy());

  await widget.open();
  await settle();

  const tag = widget.root.querySelector('.wayfindr-widget__message-automated');

  assert.ok(tag, 'no tag was rendered, so this proves nothing');
  assert.equal(tag.textContent, 'Automatisch', 'a German panel tagged the opener in English');
});

test('a change to the flag alone re-renders the message', async (t) => {
  // The timeline is left untouched when nothing it renders has changed, judged
  // by a signature of everything the renderer reads. The tag is read now, so
  // it belongs in that signature -- otherwise the same message, flagged on a
  // later answer, would keep the rendering it had before.
  //
  // Everything but the flag is held constant, so nothing else can change the
  // signature and mask a flag it leaves out.
  let answers = 0;
  const createdAt = minutesAgo(1);
  const widget = automatedSenderWidget({
    transcript: () => {
      answers++;

      return [{
        id: 1,
        sender: answers === 1 ? { kind: 'agent', name: 'Shop support' } : { kind: 'agent', name: 'Shop support', automated: true },
        type: 'text',
        body: 'Questions about plans?',
        attachments: [],
        created_at: createdAt,
      }];
    },
  });
  t.after(() => widget.destroy());

  await widget.open();
  await settle();

  assert.equal(widget.root.querySelector('.wayfindr-widget__message-automated'), null, 'the first answer was not the unflagged one, so this proves nothing');

  widget.root.querySelector('.wayfindr-widget__refresh').click();
  await settle();

  assert.ok(answers >= 2, 'the refresh never asked again, so this proves nothing');
  assert.ok(
    widget.root.querySelector('.wayfindr-widget__message-automated'),
    'the flag changed and the timeline kept its old rendering',
  );
});

test('the name and the tag are two words, not one run-together string', async (t) => {
  // What a screen reader announces for the name line.
  const widget = automatedSenderWidget();
  t.after(() => widget.destroy());

  await widget.open();
  await settle();

  const name = widget.root.querySelector('.wayfindr-widget__message-name');

  assert.equal(name.textContent, 'Shop support Automated', 'the name and the tag run together for a screen reader');
});

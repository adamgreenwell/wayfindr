const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const Wayfindr = require('../src/wayfindr-widget.js');

// A consent answer names the request it answers, so a grant cannot land on a
// request the visitor never saw. The server publishes that name with the prompt
// and nowhere else; these pin that the widget carries it on a grant, carries
// nothing on a stop, and recovers when the request has moved on.

function jsonResponse(status, payload) {
  return { ok: status >= 200 && status < 300, status, json: async () => payload };
}

async function settle() {
  for (let i = 0; i < 4; i += 1) {
    await new Promise((resolve) => setImmediate(resolve));
  }
}

function consentWidget(options) {
  options = options || {};

  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="support"></div></body></html>', {
    url: 'https://docs.example.test/install',
  });

  const requests = [];
  let cobrowse = options.cobrowse || { status: 'unavailable', consent: 'unavailable' };
  let consentAttempts = 0;

  const widget = Wayfindr.init({
    document: dom.window.document,
    location: dom.window.location,
    mount: '#support',
    apiBaseUrl: 'http://127.0.0.1:8000',
    sitePublicKey: 'site_public_docs',
    storage: null,
    mutationFlushMs: 0,
    cobrowseStatusPollMs: 0,
    messagePollMs: 0,
    realtime: false,
    fetch: async (url, init) => {
      const body = init && init.body ? JSON.parse(init.body) : null;
      requests.push({ url, body });

      if (url.includes('/cobrowse-consent')) {
        consentAttempts += 1;

        if (options.refuseFirstGrant && consentAttempts === 1 && body.granted) {
          // What the server sends when the answer names a request that is no
          // longer the open one.
          const stale = new Error('This cobrowse request has changed since it was shown.');
          stale.status = 422;
          throw stale;
        }

        return jsonResponse(200, {
          data: {
            conversation: { support_code: 'WF-TEST123' },
            cobrowse: body.granted
              ? { status: 'granted', consent: 'granted' }
              : { status: 'revoked', consent: 'revoked' },
          },
        });
      }

      if (url.includes('/cobrowse')) {
        return jsonResponse(200, {
          data: { conversation: { support_code: 'WF-TEST123' }, cobrowse: cobrowse },
        });
      }

      if (url.endsWith('/api/widget/bootstrap')) {
        return jsonResponse(200, {
          data: {
            site: { public_key: 'site_public_docs', settings: {} },
            visitor: { anonymous_id: 'anon-docs', token: 'token-docs', token_expires_in: null },
          },
        });
      }

      if (url.endsWith('/api/conversations')) {
        return jsonResponse(201, { data: { support_code: 'WF-TEST123', status: 'open' } });
      }

      return jsonResponse(200, {
        data: {
          conversation: { support_code: 'WF-TEST123', status: 'open' },
          messages: [],
        },
      });
    },
  });

  return {
    widget,
    dom,
    requests,
    setCobrowse: (next) => {
      cobrowse = next;
    },
    consentBodies: () => requests.filter((r) => r.url.includes('/cobrowse-consent')).map((r) => r.body),
    statusCalls: () => requests.filter((r) => r.url.includes('/cobrowse') && !r.url.includes('consent')).length,
  };
}

async function showPrompt(harness) {
  harness.widget.open();

  harness.widget.root.querySelector('.wayfindr-widget__textarea').value = 'Help?';
  harness.widget.root.querySelector('.wayfindr-widget__form').dispatchEvent(
    new harness.dom.window.Event('submit', { bubbles: true, cancelable: true }),
  );
  await settle();

  await harness.widget.refreshCobrowseStatus();
  await settle();
}

async function answerPrompt(harness, allow) {
  harness.widget.root
    .querySelector(allow ? '.wayfindr-widget__cobrowse-allow' : '.wayfindr-widget__cobrowse-decline')
    .click();
  await settle();
}

async function openPromptAndAnswer(harness, allow) {
  await showPrompt(harness);
  await answerPrompt(harness, allow);
}

test('a grant names the request the prompt published', async () => {
  const harness = consentWidget({
    cobrowse: {
      status: 'requested',
      consent: 'requested',
      requested_by: { name: 'Ada Agent' },
      consent_ticket: 'ticket-for-request-1',
    },
  });

  await openPromptAndAnswer(harness, true);

  const grants = harness.consentBodies().filter((b) => b.granted);

  assert.equal(grants.length, 1);
  assert.equal(
    grants[0].consent_ticket,
    'ticket-for-request-1',
    'Without the name, the server picks the target itself and a grant can land on a request the visitor never saw.'
  );

  harness.dom.window.close();
});

test('a stop names nothing, because it must never be refused for want of one', async () => {
  const harness = consentWidget({
    cobrowse: { status: 'granted', consent: 'granted', consent_ticket: null },
  });

  await openPromptAndAnswer(harness, false);

  const stops = harness.consentBodies().filter((b) => b.granted === false);

  assert.equal(stops.length, 1);
  assert.ok(
    !('consent_ticket' in stops[0]),
    'A stop carries no ticket at all -- the worst outcome this endpoint has is a share that will not stop.'
  );

  harness.dom.window.close();
});

test('a grant refused as stale re-asks once, so the next click can work', async () => {
  const harness = consentWidget({
    cobrowse: {
      status: 'requested',
      consent: 'requested',
      requested_by: { name: 'Ada Agent' },
      consent_ticket: 'ticket-for-request-1',
    },
    refuseFirstGrant: true,
  });

  await showPrompt(harness);

  // Counted across the CLICK alone. Asserting a total would have been vacuous:
  // showing the prompt already polls, so the number was over the bar before the
  // click happened, and removing the recovery left the test green.
  const before = harness.statusCalls();

  await answerPrompt(harness, true);

  assert.equal(
    harness.statusCalls() - before,
    1,
    'A refusal means the request moved on, so the prompt is re-asked exactly once rather than left showing a button that cannot work.'
  );

  harness.dom.window.close();
});

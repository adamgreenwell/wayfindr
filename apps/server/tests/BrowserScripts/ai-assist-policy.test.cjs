const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const test = require('node:test');

const root = path.resolve(__dirname, '../../../..');
const { JSDOM } = createRequire(path.join(root, 'packages/widget-js/package.json'))('jsdom');
const view = fs.readFileSync(path.join(root, 'apps/server/resources/views/agent/account/partials/ai-assist-policies.blade.php'), 'utf8');
const script = process.env.AI_ASSIST_POLICY_SCRIPT_COPY
  ? fs.readFileSync(process.env.AI_ASSIST_POLICY_SCRIPT_COPY, 'utf8')
  : view.match(/<script>([\s\S]*?)<\/script>/)[1];
const connectionScript = fs.readFileSync(path.join(root, 'apps/server/resources/views/agent/account/ai-assist.blade.php'), 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1];

function page(t, options = {}) {
  const dom = new JSDOM(`
    <div data-ai-assist>
      <form data-ai-connection-form>
        <input type="radio" name="source" value="account" checked><input name="name" value="Support connection">
        <fieldset data-ai-own-fields><select name="provider" data-ai-provider><option value="openai">OpenAI</option></select>
          <input name="model" value="fixture-model"><input name="endpoint" value="">
          <div data-ai-router-fields><input name="openrouter_provider" value=""></div>
          <input name="api_key" type="password"><input name="clear_api_key" type="checkbox" data-ai-clear-key>
        </fieldset>
        <div data-ai-test-status data-state="ready"><span data-ai-state-label>Ready</span><span data-ai-state-help>Tested</span></div>
        <button type="submit" data-ai-action="test">Test</button><button type="submit" data-ai-action="activate">Save connection</button><button type="submit" data-ai-action="discover">Discover</button>
      </form>
      <form method="post" action="/policy/sites" data-ai-policy-form>
        <input type="hidden" name="account_policy_token" value="${options.emptyTokens ? '' : 'original-account-token'}">
        <input name="mode" type="radio" value="prepare" checked>
        <fieldset ${options.disabled ? 'disabled' : ''}>
          <div hidden data-ai-policy-select-actions>
            <button type="button" data-ai-policy-select-all>Select all</button><button type="button" data-ai-policy-select-none>Clear</button>
            <span data-ai-policy-selected-count data-count-label="Selected: %count%"></span>
          </div>
          <input type="checkbox" name="site_ids[]" value="11" data-ai-policy-site><input type="hidden" name="site_tokens[11]" value="${options.emptyTokens ? '' : 'original-site-11-token'}">
          <input type="checkbox" name="site_ids[]" value="12" data-ai-policy-site><input type="hidden" name="site_tokens[12]" value="${options.emptyTokens ? '' : 'original-site-12-token'}">
          <input type="checkbox" name="site_ids[]" value="13" data-ai-policy-site disabled>
          <input type="checkbox" name="summary" value="1" checked><input type="checkbox" name="reply_draft" value="1" checked>
          <input type="hidden" name="relevant_articles" value="0"><input type="checkbox" name="relevant_articles" value="1" data-ai-policy-articles ${options.articleBlocked ? 'disabled' : ''}>
          <input type="hidden" name="article_context_consent" value="0"><input type="checkbox" name="article_context_consent" value="1" data-ai-policy-consent ${options.articleBlocked ? 'disabled' : ''}>
          <input name="max_context_characters" type="number" min="1000" value="1000">
          <button type="submit">Save selected policy</button>
        </fieldset>
      </form>
    </div>`, { url: 'http://127.0.0.1/dashboard/account/ai-assist', runScripts: 'outside-only' });
  t.after(() => dom.window.close());
  const { window } = dom;
  window.fetch = () => { throw new Error('Policy controls must use native forms'); };
  window.eval(script);
  if (options.withConnection) window.eval(connectionScript);
  return { window, form: window.document.querySelector('[data-ai-policy-form]') };
}

test('article sharing requires an explicit consent checkbox only while Relevant articles is on', (t) => {
  const { window, form } = page(t);
  const articles = form.querySelector('[data-ai-policy-articles]');
  const consent = form.querySelector('[data-ai-policy-consent]');
  assert.equal(consent.required, false);
  articles.checked = true;
  articles.dispatchEvent(new window.Event('change', { bubbles: true }));
  assert.equal(consent.required, true, 'Enabling article context must require explicit consent');
  assert.equal(form.checkValidity(), false);
  consent.checked = true;
  assert.equal(form.checkValidity(), true);
  articles.checked = false;
  consent.checked = false;
  articles.dispatchEvent(new window.Event('change', { bubbles: true }));
  assert.equal(consent.required, false);
  assert.equal(form.checkValidity(), true, 'Turning articles Off must not require consent');
});

test('account-blocked article sharing stays unavailable at site scope', (t) => {
  const { window, form } = page(t, { articleBlocked: true });
  assert.equal(form.querySelector('[data-ai-policy-articles]').disabled, true);
  assert.equal(form.querySelector('[data-ai-policy-consent]').required, false);
  assert.deepEqual(Array.from(new window.FormData(form).getAll('relevant_articles')), ['0']);
});

test('bulk selection targets only enabled accessible site checkboxes and preserves their reviewed tokens', (t) => {
  const { window, form } = page(t);
  assert.equal(form.querySelector('[data-ai-policy-select-actions]').hidden, false);
  form.querySelector('[data-ai-policy-select-all]').click();
  const body = new window.FormData(form);
  assert.deepEqual(Array.from(body.getAll('site_ids[]')), ['11', '12'], 'Select all must never include a disabled site');
  assert.equal(form.querySelector('[data-ai-policy-site][disabled]').checked, false, 'A disabled site must stay visibly unselected');
  assert.equal(form.querySelector('[data-ai-policy-selected-count]').textContent, 'Selected: 2');
  assert.equal(body.get('mode'), 'prepare', 'New site enablement starts with Prepare configuration');
  assert.equal(body.get('account_policy_token'), 'original-account-token');
  assert.equal(body.get('site_tokens[11]'), 'original-site-11-token');
  form.querySelector('[data-ai-policy-select-none]').click();
  assert.equal(form.querySelector('[data-ai-policy-selected-count]').textContent, 'Selected: 0');
  assert.equal(form.querySelector('[name="summary"]').checked, true, 'Clearing selection must not clear task choices');
});

test('bulk controls cannot bypass a disabled policy form or repair missing authority tokens', (t) => {
  const { window, form } = page(t, { disabled: true, emptyTokens: true });
  form.querySelector('[data-ai-policy-select-all]').dispatchEvent(new window.Event('click', { bubbles: true }));
  assert.deepEqual(Array.from(form.querySelectorAll('[data-ai-policy-site]'), (site) => site.checked), [false, false, false]);
  assert.equal(form.elements.account_policy_token.value, '', 'Missing authority must stay an empty sentinel');
  assert.equal(form.elements['site_tokens[11]'].value, '');
});

test('policy edits preserve the independently tested connection and native submit payload', (t) => {
  const { window, form } = page(t, { withConnection: true });
  const articles = form.querySelector('[data-ai-policy-articles]');
  articles.checked = true;
  articles.dispatchEvent(new window.Event('change', { bubbles: true }));
  form.querySelector('[data-ai-policy-consent]').checked = true;
  assert.equal(window.document.querySelector('[data-ai-test-status]').dataset.state, 'ready');
  assert.equal(window.document.querySelector('[data-ai-action="activate"]').disabled, false, 'Policy edits must not invalidate a connection test');
  assert.equal(new window.FormData(form).getAll('relevant_articles').at(-1), '1');
  assert.equal(new window.FormData(form).getAll('article_context_consent').at(-1), '1');
  const event = new window.SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: form.querySelector('[type="submit"]') });
  form.dispatchEvent(event);
  assert.equal(event.defaultPrevented, false, 'The browser must own the policy POST');
  assert.equal(form.getAttribute('method'), 'post');
  assert.equal(form.getAttribute('action'), '/policy/sites');
});

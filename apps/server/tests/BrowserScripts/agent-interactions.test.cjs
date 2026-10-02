const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const test = require('node:test');

// The widget CI job already installs jsdom; dashboard scripts use that same
// DOM runtime without introducing a second package or dependency lockfile.
const root = path.resolve(__dirname, '../../../..');
const { JSDOM } = createRequire(path.join(root, 'packages/widget-js/package.json'))('jsdom');

function inlineScript(relativePath) {
  const view = fs.readFileSync(path.join(root, relativePath), 'utf8');
  return view.match(/<script>([\s\S]*?)<\/script>/)[1];
}

function tabsPage(t, options = {}) {
  const dom = new JSDOM(`
    <div data-tabs id="report">
      <div role="tablist">
        <button role="tab" data-tab="volume" aria-selected="true" tabindex="0">Volume</button>
        <button role="tab" data-tab="tickets" aria-selected="false" tabindex="-1">Tickets</button>
        <button role="tab" data-tab="agents" aria-selected="false" tabindex="-1">Agents</button>
      </div>
      <div data-tab-panel="volume"><h2 id="report-volume">Volume</h2></div>
      <div data-tab-panel="tickets" hidden><h2 id="report-tickets">Tickets</h2></div>
      <div data-tab-panel="agents" hidden><h2 id="report-agents">Agents</h2></div>
    </div>`, {
    url: options.url || 'https://support.example.test/dashboard/reports?site=2',
    runScripts: 'outside-only',
  });
  t.after(() => dom.window.close());
  dom.window.HTMLElement.prototype.scrollIntoView = function () {};
  if (options.remembered) {
    dom.window.sessionStorage.setItem('wayfindr:tabs:/dashboard/reports:report', options.remembered);
  }
  const script = inlineScript('apps/server/resources/views/components/tabs.blade.php');
  dom.window.eval(script);
  return { window: dom.window };
}

test('keyboard tab selection stays selected after a reload with an earlier clicked tab in the URL', (t) => {
  const { window } = tabsPage(t);
  const tickets = window.document.querySelector('[data-tab="tickets"]');
  tickets.click();
  tickets.focus();
  tickets.dispatchEvent(new window.KeyboardEvent('keydown', {
    key: 'ArrowRight', bubbles: true, cancelable: true,
  }));

  assert.equal(window.document.activeElement.dataset.tab, 'agents');
  assert.equal(window.location.hash, '#tab-agents');
  assert.equal(window.location.search, '?site=2');
  assert.equal(window.sessionStorage.getItem('wayfindr:tabs:/dashboard/reports:report'), 'agents');

  // A fresh document runs the load resolver against the current URL and the
  // remembered choice, exactly as it does on refresh.
  const reloaded = tabsPage(t, { url: window.location.href, remembered: 'agents' }).window;
  assert.equal(reloaded.document.querySelector('[aria-selected="true"]').dataset.tab, 'agents');
  assert.equal(reloaded.document.querySelector('[data-tab-panel="agents"]').hidden, false);
  assert.equal(reloaded.document.querySelector('[data-tab-panel="tickets"]').hidden, true);
});

test('tab arrow wrapping, Home, and End update focus, selection, and the shareable fragment together', (t) => {
  const { window } = tabsPage(t);
  window.document.querySelector('[data-tab="volume"]').focus();

  for (const [key, selected] of [['ArrowLeft', 'agents'], ['ArrowRight', 'volume'], ['End', 'agents'], ['Home', 'volume']]) {
    const event = new window.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
    window.document.activeElement.dispatchEvent(event);

    assert.equal(event.defaultPrevented, true);
    assert.equal(window.document.activeElement.dataset.tab, selected);
    assert.equal(window.location.hash, '#tab-' + selected);
    assert.deepEqual(Array.from(window.document.querySelectorAll('[role="tab"][tabindex="0"]'), (tab) => tab.dataset.tab), [selected]);
    assert.deepEqual(Array.from(window.document.querySelectorAll('[data-tab-panel]:not([hidden])'), (panel) => panel.dataset.tabPanel), [selected]);
  }
});

test('loading an anchor inside a hidden tab preserves the original anchor', (t) => {
  const { window } = tabsPage(t, { url: 'https://support.example.test/dashboard/reports#report-agents' });
  assert.equal(window.document.querySelector('[aria-selected="true"]').dataset.tab, 'agents');
  assert.equal(window.location.hash, '#report-agents');
});

test('restoring a remembered tab without a fragment preserves the page URL', (t) => {
  const { window } = tabsPage(t, { remembered: 'tickets' });
  assert.equal(window.document.querySelector('[aria-selected="true"]').dataset.tab, 'tickets');
  assert.equal(window.location.hash, '');
});

function replyPage(t, uploadState) {
  const dom = new JSDOM(`
    <button id="outside">Other action</button>
    <form data-reply-composer data-attachments-url="https://support.example.test/attachments" data-submitting-label="Sending">
      <textarea data-reply-body data-shortcut-submit></textarea>
      <input type="file" data-reply-file-input hidden>
      <button type="button" data-reply-attach>Attach</button>
      <ul data-reply-attachments hidden></ul>
      <p data-reply-status aria-live="polite"></p>
      <button type="submit" data-reply-submit>Send</button>
    </form>`, { url: 'https://support.example.test/dashboard/conversations/preview', runScripts: 'outside-only' });
  t.after(() => dom.window.close());
  const { window } = dom;
  const requests = [];
  let finishUpload;
  const upload = new Promise((resolve) => { finishUpload = resolve; });
  const response = uploadState === 'error'
    ? { ok: false, status: 422, json: async () => ({ message: 'File rejected.' }) }
    : { ok: true, status: 200, json: async () => ({ data: { attachment: { id: 7, size_bytes: 12 } } }) };
  window.fetch = async (url, options) => {
    requests.push({ url, method: options.method });
    if (options.method === 'DELETE') return { ok: true };
    return uploadState === 'pending' ? upload : response;
  };
  const copy = {
    sending: 'Sending', attachment: 'Attachment', uploading: 'Uploading',
    remove: 'Remove :name', attach_failed: 'Upload failed', waiting_uploads: 'Waiting for uploads',
  };
  const script = inlineScript('apps/server/resources/views/agent/partials/reply-composer-script.blade.php')
    .replace(/@json\(__\('composer\.([^']+)'(?:, \[[^\]]*\])?\)\)/g, (_, key) => JSON.stringify(copy[key]));
  assert.equal(script.includes('@json'), false, 'every Blade copy expression must be resolved in the fixture');
  window.eval(script);
  const input = window.document.querySelector('[data-reply-file-input]');
  Object.defineProperty(input, 'files', { value: [new window.File(['preview'], 'preview.txt')] });
  input.dispatchEvent(new window.Event('change', { bubbles: true }));
  return { window, requests, finishUpload: () => finishUpload(response) };
}

for (const state of ['pending', 'ready', 'error']) {
  test(`removing a focused ${state} reply attachment returns focus to Attach`, async (t) => {
    const { window, requests, finishUpload } = replyPage(t, state);
    await new Promise((resolve) => setImmediate(resolve));
    const remove = window.document.querySelector('.reply-attach-chip-remove');
    remove.focus();
    remove.click();

    assert.equal(window.document.querySelector('.reply-attach-chip'), null);
    assert.equal(window.document.activeElement, window.document.querySelector('[data-reply-attach]'));
    assert.equal(window.document.querySelector('[data-reply-attachments]').hidden, true);
    assert.equal(window.document.querySelector('[data-reply-submit]').disabled, false);

    finishUpload();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(requests.filter((request) => request.method === 'DELETE').length, state === 'error' ? 0 : 1);
  });
}

test('removing an unfocused attachment preserves focus on another control', async (t) => {
  const { window } = replyPage(t, 'ready');
  await new Promise((resolve) => setImmediate(resolve));
  const outside = window.document.querySelector('#outside');
  outside.focus();
  window.document.querySelector('.reply-attach-chip-remove').click();
  assert.equal(window.document.activeElement, outside);
});

test('the submitting guard keeps a focused attachment and its focus intact', async (t) => {
  const { window } = replyPage(t, 'ready');
  await new Promise((resolve) => setImmediate(resolve));
  const remove = window.document.querySelector('.reply-attach-chip-remove');
  window.document.querySelector('form').setAttribute('data-submitting', 'true');
  remove.focus();
  remove.click();
  assert.equal(window.document.activeElement, remove);
  assert.ok(window.document.querySelector('.reply-attach-chip'));
});

function reportChartsPage(t, options = {}) {
  const dom = new JSDOM(`
    <div class="chart-scroll" id="other-chart"></div>
    <div data-tabs id="support-report">
      <div role="tablist">
        <button role="tab" data-tab="volume" aria-selected="true" tabindex="0">Volume</button>
        <button role="tab" data-tab="tickets" aria-selected="false" tabindex="-1">Tickets</button>
      </div>
      <div data-tab-panel="volume">
        <div class="chart-scroll" id="volume-chart"><div class="chart__day">Oldest</div><div class="chart__day">Today</div></div>
      </div>
      <div data-tab-panel="tickets" hidden>
        <div class="chart-scroll" id="ticket-chart"><div class="chart__day">Oldest</div><div class="chart__day">Today</div></div>
      </div>
    </div>`, {
    url: options.url || 'https://support.example.test/dashboard/reports?report_days=90',
    runScripts: 'outside-only',
  });
  t.after(() => dom.window.close());
  const { window } = dom;
  // jsdom has no layout engine. Model the measured overflow and the zero
  // width a hidden tab reports; all selection/scroll logic is the real script.
  for (const chart of window.document.querySelectorAll('.chart-scroll')) {
    Object.defineProperty(chart, 'clientWidth', {
      get: () => chart.closest('[data-tab-panel]')?.hidden ? 0 : 420,
    });
    Object.defineProperty(chart, 'scrollWidth', {
      get: () => chart.clientWidth === 0 ? 0 : (options.fits ? 420 : 1800),
    });
  }
  window.eval(inlineScript('apps/server/resources/views/components/tabs.blade.php'));
  window.eval(inlineScript('apps/server/resources/views/agent/reports/partials/chart-scroll-script.blade.php'));
  return window;
}

test('an overflowing Reports chart opens on the latest days without reordering them or touching other charts', (t) => {
  const window = reportChartsPage(t);
  assert.equal(window.document.querySelector('#volume-chart').scrollLeft, 1380);
  assert.equal(window.document.querySelector('#ticket-chart').scrollLeft, 0, 'a hidden panel must wait for a visible width');
  assert.equal(window.document.querySelector('#other-chart').scrollLeft, 0);
  assert.deepEqual(Array.from(window.document.querySelectorAll('#volume-chart .chart__day'), (day) => day.textContent), ['Oldest', 'Today']);
});

test('the Tickets chart initializes on first reveal and later tab changes preserve manual scrolling', (t) => {
  const window = reportChartsPage(t);
  const volume = window.document.querySelector('#volume-chart');
  const tickets = window.document.querySelector('#ticket-chart');
  volume.scrollLeft = 120;

  window.document.querySelector('[data-tab="tickets"]').click();
  assert.equal(tickets.scrollLeft, 1380);
  tickets.scrollLeft = 240;
  window.document.querySelector('[data-tab="volume"]').click();
  assert.equal(volume.scrollLeft, 120);
  window.document.querySelector('[data-tab="tickets"]').click();
  assert.equal(tickets.scrollLeft, 240);
});

test('a deep link that opens Reports on Tickets initializes only its visible chart', (t) => {
  const window = reportChartsPage(t, { url: 'https://support.example.test/dashboard/reports#tab-tickets' });
  assert.equal(window.document.querySelector('#ticket-chart').scrollLeft, 1380);
  assert.equal(window.document.querySelector('#volume-chart').scrollLeft, 0);
});

test('Reports charts that fit stay at the beginning', (t) => {
  const window = reportChartsPage(t, { fits: true });
  assert.equal(window.document.querySelector('#volume-chart').scrollLeft, 0);
  window.document.querySelector('[data-tab="tickets"]').click();
  assert.equal(window.document.querySelector('#ticket-chart').scrollLeft, 0);
});

test('a chart that starts fitting initializes when it first overflows, then preserves manual scrolling on resize', (t) => {
  const options = { fits: true };
  const window = reportChartsPage(t, options);
  const volume = window.document.querySelector('#volume-chart');
  assert.equal(volume.scrollLeft, 0);

  options.fits = false;
  window.dispatchEvent(new window.Event('resize'));
  assert.equal(volume.scrollLeft, 1380);

  volume.scrollLeft = 180;
  window.dispatchEvent(new window.Event('resize'));
  assert.equal(volume.scrollLeft, 180);
  assert.equal(window.document.querySelector('#ticket-chart').scrollLeft, 0, 'resize must not initialize a hidden chart');
});

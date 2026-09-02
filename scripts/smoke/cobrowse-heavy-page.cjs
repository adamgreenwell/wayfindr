const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { performance } = require('node:perf_hooks');

const { chromium } = require('playwright');

const baseUrl = requiredEnv('WAYFINDR_BASE_URL').replace(/\/+$/, '');
const agentEmail = requiredEnv('WAYFINDR_AGENT_EMAIL');
const agentPassword = requiredEnv('WAYFINDR_AGENT_PASSWORD');
const siteId = process.env.WAYFINDR_SITE_ID || '';
const outputPath = process.env.WAYFINDR_COBROWSE_OUTPUT || '';
const artifactDir = process.env.WAYFINDR_COBROWSE_ARTIFACT_DIR || '';
const runs = positiveIntegerEnv('WAYFINDR_COBROWSE_RUNS', 3);
const cardCount = positiveIntegerEnv('WAYFINDR_COBROWSE_CARDS', 600);
const steadyBatches = positiveIntegerEnv('WAYFINDR_COBROWSE_STEADY_BATCHES', 25);
const burstRecords = positiveIntegerEnv('WAYFINDR_COBROWSE_BURST_RECORDS', 320);
const timeoutMs = positiveIntegerEnv('WAYFINDR_COBROWSE_TIMEOUT_MS', 45000);
const headed = process.env.WAYFINDR_COBROWSE_HEADED === '1';

// Deliberately recognizable, but never emitted. The harness searches every
// cobrowse request and the rendered agent preview for it.
const maskedSentinel = 'WAYFINDR_MASK_SENTINEL_DO_NOT_TRANSPORT';

run().catch((error) => {
  console.error(error.stack || error.message || error);
  process.exit(1);
});

async function run() {
  const browser = await chromium.launch({
    headless: !headed,
    args: ['--enable-precise-memory-info'],
  });
  const samples = [];

  try {
    for (let index = 0; index < runs; index += 1) {
      samples.push(await runSample(browser, index + 1));
    }

    const report = buildReport(browser.version(), samples);
    const json = `${JSON.stringify(report, null, 2)}\n`;

    if (outputPath) {
      fs.mkdirSync(path.dirname(path.resolve(outputPath)), { recursive: true });
      fs.writeFileSync(outputPath, json);
    }

    process.stdout.write(json);
  } finally {
    await browser.close();
  }
}

async function runSample(browser, runNumber) {
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
  });
  const visitorPage = await context.newPage();
  const agentPage = await context.newPage();
  const visitorNetwork = observeNetwork(visitorPage);
  const agentNetwork = observeNetwork(agentPage);
  const realtime = observeCobrowseRealtime(agentPage);
  const pageErrors = [];
  let consoleErrors = 0;

  for (const page of [visitorPage, agentPage]) {
    page.on('pageerror', (error) => pageErrors.push(safeErrorName(error)));
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors += 1;
      }
    });
  }

  let abortNextMutation = false;

  await visitorPage.route('**/api/conversations/*/cobrowse-mutations', async (route) => {
    if (abortNextMutation) {
      abortNextMutation = false;
      visitorNetwork.markInjectedFailure(route.request());
      await route.abort('failed');

      return;
    }

    await route.continue();
  });

  try {
    await login(visitorPage);
    const testerUrl = await resolveTesterUrl(visitorPage);
    await visitorPage.goto(testerUrl, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
    await visitorPage.locator('.wayfindr-widget__launcher').first().waitFor({ state: 'visible', timeout: timeoutMs });

    const pageShape = await buildHeavyPage(visitorPage, runNumber);
    const clientBudget = await visitorPage.evaluate(() => ({ ...window.Wayfindr.cobrowsePayloadBudget }));

    assertExactBudgets(clientBudget, {
      mutationBatchMaxBytes: 60000,
      mutationQueueMaxRecords: 250,
      mutationFlushMs: 50,
      pressureResyncMs: 30000,
      statusPollMs: 5000,
      resyncMaxAttempts: 3,
    }, 'stock widget');

    const supportCode = await createConversation(visitorPage, runNumber);
    const detailUrl = `${baseUrl}/dashboard/conversations/${encodeURIComponent(supportCode)}?conversation_tab=cobrowse`;

    await agentPage.goto(detailUrl, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
    await requestCobrowse(agentPage);
    await waitForRealtimeListener(agentPage);

    const consentPromise = waitForSuccessfulResponse(visitorPage, 'consent');
    const telemetryPromise = waitForSuccessfulResponse(visitorPage, 'telemetry');
    const pageStatePromise = waitForSuccessfulResponse(visitorPage, 'page_state');
    const initialSnapshotPromise = waitForSuccessfulResponse(visitorPage, 'snapshot', (response) => {
      return !requestJson(response.request())?.mutation_sequence;
    });

    await visitorPage.locator('.wayfindr-widget__cobrowse-allow').first().waitFor({ state: 'visible', timeout: timeoutMs });
    await visitorPage.locator('.wayfindr-widget__cobrowse-allow').first().click();

    const [consentResponse, telemetryResponse, pageStateResponse, initialSnapshotResponse] = await Promise.all([
      consentPromise,
      telemetryPromise,
      pageStatePromise,
      initialSnapshotPromise,
    ]);

    const consentMetric = await responseMetric(consentResponse, visitorNetwork, maskedSentinel);
    const telemetryMetric = await responseMetric(telemetryResponse, visitorNetwork, maskedSentinel);
    const pageStateMetric = await responseMetric(pageStateResponse, visitorNetwork, maskedSentinel);
    const initialSnapshotMetric = await responseMetric(initialSnapshotResponse, visitorNetwork, maskedSentinel);
    const initialSnapshotBody = await initialSnapshotResponse.json();
    const serverBudget = initialSnapshotBody?.data?.payload_budget || {};
    const initialSnapshot = initialSnapshotBody?.data?.snapshot || {};

    assertExactBudgets(serverBudget, {
      snapshot_html_max_characters: 65535,
      snapshot_text_max_characters: 10000,
      mutation_batch_max_items: 50,
      mutation_text_max_characters: 5000,
      mutation_html_max_characters: 10000,
      mutation_attribute_value_max_characters: 2048,
      mutation_recent_batches_retained: 20,
      telemetry_payload_max_bytes: 10485760,
      widget_mutation_batch_max_bytes: 60000,
      widget_mutation_queue_max_records: 250,
      widget_mutation_flush_ms: 50,
      widget_pressure_resync_ms: 30000,
      widget_status_poll_ms: 5000,
      widget_resync_max_attempts: 3,
    }, 'server');

    await agentPage.locator('[data-cobrowse-replay-frame]').waitFor({ state: 'attached', timeout: timeoutMs });
    await waitForRealtimeListener(agentPage);

    const initialPrivacy = await previewPrivacy(agentPage);
    const mutationMetrics = [];
    let finalSteadyMarker = '';
    const workloadStartedAt = performance.now();

    for (let wave = 1; wave <= steadyBatches; wave += 1) {
      finalSteadyMarker = `wf-steady-r${runNumber}-w${wave}`;
      const responsePromise = waitForSuccessfulResponse(visitorPage, 'mutations');

      await mutateSteady(visitorPage, wave, finalSteadyMarker, maskedSentinel);

      const response = await responsePromise;
      const metric = await responseMetric(response, visitorNetwork, maskedSentinel);
      const body = await response.json();

      mutationMetrics.push({
        ...metric,
        ...numericMutationSummary(body?.data?.mutations),
      });
    }

    await waitForPreviewMarker(agentPage, finalSteadyMarker);

    const consoleErrorsBeforeInjectedLoss = consoleErrors;
    abortNextMutation = true;
    const failedRequestPromise = visitorPage.waitForEvent('requestfailed', {
      predicate: (request) => requestKind(request.url()) === 'mutations',
      timeout: timeoutMs,
    });

    await mutateSteady(visitorPage, steadyBatches + 1, `wf-forced-drop-r${runNumber}`, maskedSentinel);
    await failedRequestPromise;

    const finalMarker = `wf-pressure-r${runNumber}`;
    const pressureMutationPromise = waitForSuccessfulResponse(visitorPage, 'mutations');
    const pressureSnapshotPromise = waitForSuccessfulResponse(visitorPage, 'snapshot', (response) => {
      return Number(requestJson(response.request())?.mutation_sequence || 0) > 0;
    });
    const deliveryStartedAt = performance.now();

    await mutateBurst(visitorPage, burstRecords, finalMarker, maskedSentinel);

    const pressureMutationResponse = await pressureMutationPromise;
    const pressureMutationMetric = await responseMetric(pressureMutationResponse, visitorNetwork, maskedSentinel);
    const pressureMutationBody = await pressureMutationResponse.json();
    const pressureSummary = numericMutationSummary(pressureMutationBody?.data?.mutations);
    const pressureSnapshotResponse = await pressureSnapshotPromise;
    const pressureSnapshotMetric = await responseMetric(pressureSnapshotResponse, visitorNetwork, maskedSentinel);
    const pressureSnapshotRequest = requestJson(pressureSnapshotResponse.request()) || {};

    await waitForPreviewMarker(agentPage, finalMarker);
    const deliveryMs = performance.now() - deliveryStartedAt;
    const workloadMs = performance.now() - workloadStartedAt;
    const finalPrivacy = await previewPrivacy(agentPage);
    const browserTiming = await measureAgentReload(agentPage, agentNetwork);
    const finalMutation = mutationMetrics.at(-1) || {};
    const successfulBatches = pressureSummary.batch_count || finalMutation.batch_count || 0;
    const retainedBatches = pressureSummary.recent_batches_count || finalMutation.recent_batches_count || 0;
    const allObservedRequests = [...visitorNetwork.requests, ...agentNetwork.requests];
    const allTransportFailures = [...visitorNetwork.failures, ...agentNetwork.failures];
    const failedResponses = [...visitorNetwork.failedResponses, ...agentNetwork.failedResponses];
    const injectedFailures = allTransportFailures.filter((failure) => failure.injected).length;
    const naturalFailures = allTransportFailures.filter((failure) => !failure.injected).length
      + failedResponses.length;
    const mutationRequests = visitorNetwork.records.filter((record) => record.kind === 'mutations');
    const statusPolls = visitorNetwork.responses.filter((response) => {
      return response.kind === 'status' && response.started_at_ms >= workloadStartedAt;
    }).length;

    const allTransportMasked = [
      consentMetric,
      telemetryMetric,
      pageStateMetric,
      initialSnapshotMetric,
      pressureMutationMetric,
      pressureSnapshotMetric,
      ...mutationMetrics,
    ].every((metric) => metric.masked_sentinel_absent === true)
      && allObservedRequests.every((request) => request.masked_sentinel_absent === true);

    assert(initialPrivacy.masked_sentinel_absent, 'masked sentinel reached the initial agent preview');
    assert(finalPrivacy.masked_sentinel_absent, 'masked sentinel reached the final agent preview');
    assert(initialPrivacy.unsafe_markup_absent, 'unsafe markup reached the initial agent preview');
    assert(finalPrivacy.unsafe_markup_absent, 'unsafe markup reached the final agent preview');
    assert(allTransportMasked, 'masked sentinel reached a cobrowse request');
    assert(pressureSummary.skipped_count > 0, 'pressure wave did not exercise skipped-record accounting');
    assert(pressureSummary.dropped_count > 0, 'forced loss was not reported by the following mutation batch');
    assert(Number(pressureSnapshotRequest.mutation_sequence || 0) > 0, 'pressure did not trigger a snapshot resync');
    assert(retainedBatches === 20, `server retained ${retainedBatches} mutation batches instead of 20`);
    assert(successfulBatches > retainedBatches, 'workload did not exercise retained-batch trimming');
    assert(realtime.update_events > 0, 'agent received no cobrowse Reverb updates');
    assert(agentNetwork.records.some((record) => record.kind === 'preview'), 'Reverb updates triggered no agent preview fetch');
    assert(statusPolls > 0, 'workload completed without exercising a periodic cobrowse status poll');
    assert(naturalFailures === 0, `observed ${naturalFailures} unplanned cobrowse request failures`);
    assert(pageErrors.length === 0, `browser reported ${pageErrors.length} uncaught page errors`);

    return {
      run: runNumber,
      support_code: supportCode,
      page: pageShape,
      workload_ms: round(workloadMs),
      client: {
        successful_mutation_batches: successfulBatches,
        skipped_records: pressureSummary.skipped_count || 0,
        dropped_batches: pressureSummary.dropped_count || 0,
        injected_request_failures: injectedFailures,
        natural_request_failures: naturalFailures,
        periodic_cobrowse_status_polls: statusPolls,
        console_errors_before_forced_loss: consoleErrorsBeforeInjectedLoss,
        console_errors_during_forced_loss: Math.max(0, consoleErrors - consoleErrorsBeforeInjectedLoss),
        uncaught_page_errors: pageErrors.length,
        mutation_request_bytes: distribution(mutationRequests.map((record) => record.request_bytes)),
        mutation_http_ms: distribution(mutationRequests.map((record) => record.http_ms)),
      },
      http: {
        consent: consentMetric,
        telemetry: telemetryMetric,
        page_state: pageStateMetric,
        initial_snapshot: {
          ...initialSnapshotMetric,
          html_characters: numeric(initialSnapshot.html_length),
          text_characters: numeric(initialSnapshot.text_length),
          node_count: numeric(initialSnapshot.node_count),
          masked_count: numeric(initialSnapshot.masked_count),
        },
        pressure_mutation: pressureMutationMetric,
        pressure_resync_snapshot: pressureSnapshotMetric,
      },
      retention: {
        batch_count: successfulBatches,
        retained_batches: retainedBatches,
        trimmed_batches: Math.max(0, successfulBatches - retainedBatches),
        accepted_mutations: pressureSummary.mutation_count || 0,
      },
      realtime: {
        websocket_connections: realtime.connections,
        websocket_closes: realtime.closes,
        cobrowse_update_events: realtime.update_events,
        update_kinds: { ...realtime.update_kinds },
        automatic_preview_requests: agentNetwork.records.filter((record) => record.kind === 'preview').length,
        pressure_wave_to_preview_ms: round(deliveryMs),
      },
      agent_render: browserTiming,
      pressure_recovery: {
        snapshot_resync_observed: true,
        mutation_sequence: numeric(pressureSnapshotRequest.mutation_sequence),
      },
      privacy: {
        masked_sentinel_absent_from_transport: allTransportMasked,
        masked_sentinel_absent_from_preview: initialPrivacy.masked_sentinel_absent && finalPrivacy.masked_sentinel_absent,
        unsafe_markup_absent_from_preview: initialPrivacy.unsafe_markup_absent && finalPrivacy.unsafe_markup_absent,
      },
      budgets: {
        client: clientBudget,
        server: numericObject(serverBudget),
      },
    };
  } catch (error) {
    await captureFailureScreenshots(visitorPage, agentPage, runNumber);
    throw error;
  } finally {
    await context.close();
  }
}

async function login(page) {
  await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
  await page.locator('#email').fill(agentEmail);
  await page.locator('#password').fill(agentPassword);

  await Promise.all([
    page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: timeoutMs }),
    page.locator('form button[type="submit"]').click(),
  ]);
}

async function resolveTesterUrl(page) {
  if (siteId) {
    return `${baseUrl}/dashboard/sites/${encodeURIComponent(siteId)}/tester`;
  }

  await page.goto(`${baseUrl}/dashboard/sites`, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
  const link = page.locator('a[href*="/dashboard/sites/"][href$="/tester"]').first();
  await link.waitFor({ state: 'attached', timeout: timeoutMs });
  const href = await link.getAttribute('href');

  if (!href) {
    throw new Error('No supported site tester link was available to the measurement agent.');
  }

  return new URL(href, baseUrl).toString();
}

async function buildHeavyPage(page, runNumber) {
  return page.evaluate(({ count, sentinel, run }) => {
    const startedAt = performance.now();
    const old = document.querySelector('#wayfindr-cobrowse-load');

    if (old) {
      old.remove();
    }

    const container = document.createElement('section');
    container.id = 'wayfindr-cobrowse-load';
    container.setAttribute('aria-label', 'Synthetic cobrowse load surface');
    const fragment = document.createDocumentFragment();

    for (let index = 0; index < count; index += 1) {
      const card = document.createElement('article');
      card.className = `wf-load-card wf-load-card-${index % 12}`;
      card.dataset.index = String(index);

      const heading = document.createElement('h3');
      heading.textContent = `Synthetic row ${index}`;
      card.appendChild(heading);

      const mutable = document.createElement('p');
      mutable.className = 'wf-load-mutable';
      mutable.textContent = `Stable synthetic value ${index}`;
      card.appendChild(mutable);

      const state = document.createElement('span');
      state.className = 'wf-load-state';
      state.textContent = index % 2 === 0 ? 'ready' : 'waiting';
      card.appendChild(state);

      const action = document.createElement('button');
      action.type = 'button';
      action.textContent = `Synthetic action ${index}`;
      card.appendChild(action);

      if (index % 40 === 0) {
        const masked = document.createElement('div');
        masked.dataset.wayfindrMask = '';
        masked.className = 'wf-load-masked';
        masked.textContent = `${sentinel}-${run}-${index}`;
        card.appendChild(masked);
      }

      if (index === 0) {
        card.setAttribute('onclick', 'throw new Error("unsafe")');
        const unsafe = document.createElement('a');
        unsafe.href = 'javascript:void(0)';
        unsafe.textContent = 'Unsafe synthetic link';
        card.appendChild(unsafe);
      }

      fragment.appendChild(card);
    }

    container.appendChild(fragment);
    (document.querySelector('main') || document.body).appendChild(container);

    return {
      cards: count,
      elements: container.querySelectorAll('*').length,
      masked_regions: container.querySelectorAll('[data-wayfindr-mask]').length,
      build_ms: Math.round((performance.now() - startedAt) * 10) / 10,
    };
  }, { count: cardCount, sentinel: maskedSentinel, run: runNumber });
}

async function createConversation(page, runNumber) {
  const responsePromise = waitForSuccessfulResponse(page, 'conversation');

  await page.locator('.wayfindr-widget__launcher').first().click();
  await page.locator('.wayfindr-widget__textarea').first().waitFor({ state: 'visible', timeout: timeoutMs });
  await page.locator('.wayfindr-widget__textarea').first().fill(`Synthetic cobrowse measurement run ${runNumber}.`);
  await page.locator('.wayfindr-widget__send').first().click();

  const response = await responsePromise;
  const body = await response.json();
  const supportCode = body?.data?.support_code;

  if (!supportCode) {
    throw new Error('Conversation response did not contain a support code.');
  }

  return supportCode;
}

async function requestCobrowse(page) {
  const tab = page.locator('[data-tab="cobrowse"]');

  if (await tab.count()) {
    await tab.click();
  }

  const form = page.locator('form[action$="/cobrowse/request"]').first();
  await form.waitFor({ state: 'attached', timeout: timeoutMs });

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: timeoutMs }),
    form.locator('button[type="submit"]').click(),
  ]);
}

async function waitForRealtimeListener(page) {
  await page.waitForFunction(() => {
    return document.querySelector('[data-cobrowse-update-panel]')?.dataset.state === 'listening';
  }, null, { timeout: timeoutMs });
}

async function mutateSteady(page, wave, marker, sentinel) {
  await page.evaluate(({ index, safeMarker, masked }) => {
    const card = document.querySelector('#wayfindr-cobrowse-load .wf-load-card');
    const mutable = card.querySelector('.wf-load-mutable');
    const previous = card.querySelector('.wf-load-ephemeral');
    const maskedRegion = card.querySelector('[data-wayfindr-mask]');

    mutable.firstChild.data = safeMarker;
    card.className = `wf-load-card wf-load-wave-${index % 7}`;

    if (previous) {
      previous.remove();
    }

    const added = document.createElement('span');
    added.className = 'wf-load-ephemeral';
    added.textContent = `Synthetic subtree ${index}`;
    card.appendChild(added);

    if (maskedRegion) {
      maskedRegion.firstChild.data = `${masked}-${index}`;
    }
  }, { index: wave, safeMarker: marker, masked: sentinel });
}

async function mutateBurst(page, records, marker, sentinel) {
  await page.evaluate(({ count, safeMarker, masked }) => {
    const cards = Array.from(document.querySelectorAll('#wayfindr-cobrowse-load .wf-load-card'));
    const first = cards[0];

    first.querySelector('.wf-load-mutable').firstChild.data = safeMarker;
    first.querySelector('[data-wayfindr-mask]').firstChild.data = `${masked}-pressure`;

    for (let index = 0; index < count; index += 1) {
      const card = cards[index % cards.length];
      card.className = `wf-load-card wf-load-pressure-${index}`;
    }
  }, { count: records, safeMarker: marker, masked: sentinel });
}

async function waitForPreviewMarker(page, marker) {
  await page.waitForFunction((expected) => {
    const frame = document.querySelector('[data-cobrowse-replay-frame]');

    return Boolean(frame && frame.getAttribute('srcdoc')?.includes(expected));
  }, marker, { timeout: timeoutMs });
}

async function previewPrivacy(page) {
  return page.locator('[data-cobrowse-replay-frame]').evaluate((frame, sentinel) => {
    const srcdoc = frame.getAttribute('srcdoc') || '';

    return {
      masked_sentinel_absent: !srcdoc.includes(sentinel),
      unsafe_markup_absent: !/javascript\s*:|\son(?:click|error)\s*=/i.test(srcdoc),
    };
  }, maskedSentinel);
}

async function measureAgentReload(page, network) {
  const response = await page.reload({ waitUntil: 'load', timeout: timeoutMs });

  if (!response || response.status() !== 200) {
    throw new Error(`Populated agent detail reload answered ${response ? response.status() : 'without a response'}.`);
  }

  await page.locator('[data-cobrowse-replay-frame]').waitFor({ state: 'attached', timeout: timeoutMs });
  const metric = await responseMetric(response, network, maskedSentinel);
  const browser = await page.evaluate(() => {
    const navigation = performance.getEntriesByType('navigation')[0];
    const memory = performance.memory || null;

    return {
      dom_content_loaded_ms: navigation ? navigation.domContentLoadedEventEnd - navigation.startTime : null,
      load_ms: navigation ? navigation.loadEventEnd - navigation.startTime : null,
      response_ms: navigation ? navigation.responseEnd - navigation.requestStart : null,
      used_js_heap_bytes: memory ? memory.usedJSHeapSize : null,
    };
  });

  return {
    http_status: metric.status,
    response_bytes: metric.response_bytes,
    response_ms: round(browser.response_ms),
    dom_content_loaded_ms: round(browser.dom_content_loaded_ms),
    load_ms: round(browser.load_ms),
    used_js_heap_bytes: numeric(browser.used_js_heap_bytes),
    preview_rendered: true,
  };
}

function observeNetwork(page) {
  const starts = new WeakMap();
  const durations = new WeakMap();
  const injected = new WeakSet();
  const requests = [];
  const records = [];
  const failures = [];
  const responses = [];
  const failedResponses = [];

  page.on('request', (request) => {
    const kind = requestKind(request.url());

    if (kind) {
      starts.set(request, performance.now());
      requests.push({
        kind,
        masked_sentinel_absent: !(request.postData() || '').includes(maskedSentinel),
      });
    }
  });

  page.on('requestfinished', async (request) => {
    const kind = requestKind(request.url());

    if (!kind) {
      return;
    }

    const startedAt = starts.get(request);
    const httpMs = startedAt === undefined ? null : round(performance.now() - startedAt);
    durations.set(request, httpMs);
    const response = await request.response();

    records.push({
      kind,
      status: response?.status() || 0,
      request_bytes: Buffer.byteLength(request.postData() || ''),
      http_ms: httpMs,
    });
  });

  page.on('response', (response) => {
    const kind = requestKind(response.url());

    if (!kind) {
      return;
    }

    const observed = {
      kind,
      status: response.status(),
      started_at_ms: starts.get(response.request()) ?? performance.now(),
    };
    responses.push(observed);

    if (observed.status < 200 || observed.status >= 300) {
      failedResponses.push(observed);
    }
  });

  page.on('requestfailed', (request) => {
    const kind = requestKind(request.url());

    if (kind) {
      failures.push({ kind, injected: injected.has(request) });
    }
  });

  return {
    failures,
    failedResponses,
    durations,
    records,
    requests,
    responses,
    starts,
    markInjectedFailure(request) {
      injected.add(request);
    },
  };
}

function observeCobrowseRealtime(page) {
  const result = {
    connections: 0,
    closes: 0,
    update_events: 0,
    update_kinds: {},
  };

  page.on('websocket', (socket) => {
    result.connections += 1;
    socket.on('close', () => {
      result.closes += 1;
    });
    socket.on('framereceived', (frame) => {
      let envelope;

      try {
        envelope = JSON.parse(String(frame.payload || ''));
      } catch (error) {
        return;
      }

      if (!String(envelope.event || '').endsWith('conversation.cobrowse.updated')) {
        return;
      }

      result.update_events += 1;

      try {
        const payload = typeof envelope.data === 'string' ? JSON.parse(envelope.data) : (envelope.data || {});
        const kind = String(payload?.update?.kind || 'unknown');
        result.update_kinds[kind] = (result.update_kinds[kind] || 0) + 1;
      } catch (error) {
        result.update_kinds.unknown = (result.update_kinds.unknown || 0) + 1;
      }
    });
  });

  return result;
}

async function waitForSuccessfulResponse(page, kind, predicate = () => true) {
  return page.waitForResponse((response) => {
    return requestKind(response.url()) === kind
      && response.status() >= 200
      && response.status() < 300
      && predicate(response);
  }, { timeout: timeoutMs });
}

async function responseMetric(response, network, sentinel) {
  await response.finished();
  const request = response.request();
  const body = await response.body();
  const postData = request.postData() || '';

  return {
    status: response.status(),
    request_bytes: Buffer.byteLength(postData),
    response_bytes: body.length,
    http_ms: network.durations.get(request) ?? null,
    masked_sentinel_absent: !postData.includes(sentinel),
  };
}

function requestKind(url) {
  const pathname = new URL(url).pathname;

  if (pathname === '/api/conversations') {
    return 'conversation';
  }

  if (/\/api\/conversations\/[^/]+\/cobrowse-consent$/.test(pathname)) {
    return 'consent';
  }

  if (/\/api\/conversations\/[^/]+\/cobrowse-telemetry$/.test(pathname)) {
    return 'telemetry';
  }

  if (/\/api\/conversations\/[^/]+\/cobrowse-page-state$/.test(pathname)) {
    return 'page_state';
  }

  if (/\/api\/conversations\/[^/]+\/cobrowse-snapshot$/.test(pathname)) {
    return 'snapshot';
  }

  if (/\/api\/conversations\/[^/]+\/cobrowse-mutations$/.test(pathname)) {
    return 'mutations';
  }

  if (/\/api\/conversations\/[^/]+\/cobrowse$/.test(pathname)) {
    return 'status';
  }

  if (/\/dashboard\/conversations\/[^/]+\/cobrowse\/preview$/.test(pathname)) {
    return 'preview';
  }

  if (/\/dashboard\/conversations\/[^/]+$/.test(pathname)) {
    return 'agent_detail';
  }

  return null;
}

function requestJson(request) {
  try {
    return JSON.parse(request.postData() || '');
  } catch (error) {
    return null;
  }
}

function numericMutationSummary(value) {
  value = value || {};

  return {
    last_sequence: numeric(value.last_sequence),
    batch_count: numeric(value.batch_count),
    mutation_count: numeric(value.mutation_count),
    dropped_count: numeric(value.dropped_count),
    skipped_count: numeric(value.skipped_count),
    recent_batches_count: numeric(value.recent_batches_count),
  };
}

function buildReport(browserVersion, samples) {
  const metric = (read) => distribution(samples.map(read));

  return {
    schema_version: 1,
    measured_at: new Date().toISOString(),
    environment: {
      machine: os.cpus()[0]?.model || 'unknown',
      logical_cpus: os.cpus().length,
      memory_bytes: os.totalmem(),
      platform: os.platform(),
      platform_release: os.release(),
      architecture: os.arch(),
      browser: `Chromium ${browserVersion}`,
      topology: 'one local Laravel HTTP process, one local Reverb process, PostgreSQL and Redis in local Docker, two pages in one Chromium context',
    },
    workload: {
      runs,
      cards: cardCount,
      steady_batches: steadyBatches,
      burst_mutation_records: burstRecords,
      injected_request_failures_per_run: 1,
      report_contains_page_text_or_urls: false,
    },
    budgets: samples[0]?.budgets || {},
    summary: {
      page_elements: metric((sample) => sample.page.elements),
      page_build_ms: metric((sample) => sample.page.build_ms),
      workload_ms: metric((sample) => sample.workload_ms),
      mutation_request_bytes: rollupDistributions(samples.map((sample) => sample.client.mutation_request_bytes)),
      mutation_http_ms: rollupDistributions(samples.map((sample) => sample.client.mutation_http_ms)),
      skipped_records: metric((sample) => sample.client.skipped_records),
      dropped_batches: metric((sample) => sample.client.dropped_batches),
      trimmed_batches: metric((sample) => sample.retention.trimmed_batches),
      pressure_wave_to_preview_ms: metric((sample) => sample.realtime.pressure_wave_to_preview_ms),
      agent_response_bytes: metric((sample) => sample.agent_render.response_bytes),
      agent_response_ms: metric((sample) => sample.agent_render.response_ms),
      agent_dom_content_loaded_ms: metric((sample) => sample.agent_render.dom_content_loaded_ms),
      agent_load_ms: metric((sample) => sample.agent_render.load_ms),
      agent_used_js_heap_bytes: metric((sample) => sample.agent_render.used_js_heap_bytes),
    },
    verification: {
      all_runs_received_reverb_updates: samples.every((sample) => sample.realtime.cobrowse_update_events > 0),
      all_runs_rendered_populated_preview: samples.every((sample) => sample.agent_render.preview_rendered),
      all_runs_resynced_after_pressure: samples.every((sample) => sample.pressure_recovery.snapshot_resync_observed),
      no_unplanned_request_failures: samples.every((sample) => sample.client.natural_request_failures === 0),
      no_uncaught_page_errors: samples.every((sample) => sample.client.uncaught_page_errors === 0),
      masking_held: samples.every((sample) => sample.privacy.masked_sentinel_absent_from_transport && sample.privacy.masked_sentinel_absent_from_preview),
      unsafe_markup_removed: samples.every((sample) => sample.privacy.unsafe_markup_absent_from_preview),
      retention_bounded: samples.every((sample) => sample.retention.retained_batches === 20 && sample.retention.trimmed_batches > 0),
    },
    samples,
  };
}

function distribution(values) {
  const sorted = values
    .map((value) => Number(value))
    .filter(Number.isFinite)
    .sort((left, right) => left - right);

  if (sorted.length === 0) {
    return { samples: 0, median: null, p95: null, max: null };
  }

  const middle = Math.floor(sorted.length / 2);
  const median = sorted.length % 2 === 0
    ? (sorted[middle - 1] + sorted[middle]) / 2
    : sorted[middle];
  const p95Index = Math.max(0, Math.ceil(sorted.length * 0.95) - 1);

  return {
    samples: sorted.length,
    median: round(median),
    p95: round(sorted[p95Index]),
    max: round(sorted.at(-1)),
  };
}

function rollupDistributions(values) {
  return {
    runs: values.length,
    median: distribution(values.map((value) => value.median)).median,
    p95: distribution(values.map((value) => value.p95)).p95,
    max: distribution(values.map((value) => value.max)).max,
  };
}

function numericObject(value) {
  return Object.fromEntries(Object.entries(value || {}).flatMap(([key, candidate]) => {
    const number = Number(candidate);

    return Number.isFinite(number) ? [[key, number]] : [];
  }));
}

function numeric(value) {
  const number = Number(value);

  return Number.isFinite(number) ? number : null;
}

function round(value) {
  const number = Number(value);

  return Number.isFinite(number) ? Math.round(number * 10) / 10 : null;
}

function assertExactBudgets(actual, expected, label) {
  for (const [key, value] of Object.entries(expected)) {
    assert(Number(actual?.[key]) === value, `${label} budget ${key} was ${actual?.[key]}, expected ${value}`);
  }
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function requiredEnv(name) {
  const value = process.env[name];

  if (!value) {
    throw new Error(`Set ${name} before running the cobrowse heavy-page measurement.`);
  }

  return value;
}

function positiveIntegerEnv(name, fallback) {
  const value = Number(process.env[name] || fallback);

  if (!Number.isInteger(value) || value <= 0) {
    throw new Error(`${name} must be a positive integer.`);
  }

  return value;
}

function safeErrorName(error) {
  return String(error?.name || 'Error').replace(/[^A-Za-z0-9_-]/g, '').slice(0, 40) || 'Error';
}

async function captureFailureScreenshots(visitorPage, agentPage, runNumber) {
  if (!artifactDir) {
    return;
  }

  fs.mkdirSync(artifactDir, { recursive: true });

  await Promise.all([
    visitorPage.screenshot({
      fullPage: true,
      path: path.join(artifactDir, `cobrowse-heavy-page-visitor-run-${runNumber}.png`),
    }).catch(() => {}),
    agentPage.screenshot({
      fullPage: true,
      path: path.join(artifactDir, `cobrowse-heavy-page-agent-run-${runNumber}.png`),
    }).catch(() => {}),
  ]);
}

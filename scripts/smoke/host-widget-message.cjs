const fs = require('node:fs');
const path = require('node:path');

const { chromium } = require('playwright');

const baseUrl = requiredEnv('WAYFINDR_BASE_URL').replace(/\/+$/, '');
const hostPageUrl = requiredEnv('WAYFINDR_HOST_PAGE_URL');
const sitePublicKey = requiredEnv('WAYFINDR_SITE_PUBLIC_KEY');
const message = process.env.WAYFINDR_SMOKE_MESSAGE || 'Hello from the Wayfindr host-widget smoke.';
const outputPath = process.env.WAYFINDR_WIDGET_SMOKE_OUTPUT || '';
const artifactDir = process.env.WAYFINDR_WIDGET_SMOKE_ARTIFACT_DIR || '';
const timeoutMs = Number(process.env.WAYFINDR_WIDGET_BROWSER_TIMEOUT_MS || 30000);
const headed = process.env.WAYFINDR_WIDGET_BROWSER_HEADED === '1';

run().catch(async (error) => {
  console.error(error.stack || error.message || error);
  process.exit(1);
});

async function run() {
  const browser = await chromium.launch({ headless: ! headed });
  const context = await browser.newContext({
    viewport: { width: 1280, height: 720 },
  });
  const page = await context.newPage();

  try {
    await page.goto(hostPageUrl, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
    await page.locator('.wayfindr-widget__launcher').first().waitFor({ state: 'visible', timeout: 15000 });

    await page.locator('.wayfindr-widget__launcher').first().click();
    await page.locator('.wayfindr-widget__textarea').first().waitFor({ state: 'visible', timeout: 10000 });
    await page.locator('.wayfindr-widget__textarea').first().fill(message);

    const conversationResponsePromise = page.waitForResponse((response) => {
      return response.request().method() === 'POST'
        && response.status() >= 200
        && response.status() < 300
        && isExpectedApiPath(response.url(), '/api/conversations')
        && usesExpectedSitePublicKey(response.request());
    }, { timeout: timeoutMs });

    const messageResponsePromise = page.waitForResponse((response) => {
      return response.request().method() === 'POST'
        && response.status() >= 200
        && response.status() < 300
        && isExpectedMessagePath(response.url())
        && usesExpectedSitePublicKey(response.request());
    }, { timeout: timeoutMs });

    await page.locator('.wayfindr-widget__send').first().click();

    const conversationResponse = await conversationResponsePromise;
    const conversationPayload = await conversationResponse.json();
    const supportCode = conversationPayload?.data?.support_code;

    if (! supportCode) {
      throw new Error('Wayfindr conversation response did not include data.support_code.');
    }

    await messageResponsePromise;

    await page.waitForFunction(
      (code) => document.querySelector('.wayfindr-widget__status')?.textContent.includes(`Support code ${code}`),
      supportCode,
      { timeout: 15000 },
    );
    await page.waitForFunction(
      (body) => [...document.querySelectorAll('.wayfindr-widget__message-body')]
        .some((element) => element.textContent.includes(body)),
      message,
      { timeout: 15000 },
    );

    const anonymousId = await page.evaluate((key) => {
      try {
        return window.localStorage.getItem(`wayfindr:${key}:anonymous-id`);
      } catch (error) {
        return null;
      }
    }, sitePublicKey);

    const result = {
      support_code: supportCode,
      anonymous_id: anonymousId,
      host_page_url: hostPageUrl,
      message,
    };

    if (outputPath) {
      fs.writeFileSync(outputPath, `${JSON.stringify(result, null, 2)}\n`);
    }

    console.log(`Host widget smoke sent ${supportCode}.`);
  } catch (error) {
    await captureFailureScreenshot(page);
    throw error;
  } finally {
    await context.close();
    await browser.close();
  }
}

function requiredEnv(name) {
  const value = process.env[name];

  if (! value) {
    throw new Error(`Set ${name} before running the host-widget smoke.`);
  }

  return value;
}

function isExpectedApiPath(url, apiPath) {
  const parsedUrl = new URL(url);
  const parsedBaseUrl = new URL(baseUrl);

  return parsedUrl.origin === parsedBaseUrl.origin && parsedUrl.pathname === apiPath;
}

function isExpectedMessagePath(url) {
  const parsedUrl = new URL(url);
  const parsedBaseUrl = new URL(baseUrl);

  return parsedUrl.origin === parsedBaseUrl.origin
    && /^\/api\/conversations\/[^/]+\/messages$/.test(parsedUrl.pathname);
}

function usesExpectedSitePublicKey(request) {
  const payload = requestJsonBody(request);

  return payload?.site_public_key === sitePublicKey;
}

function requestJsonBody(request) {
  const postData = request.postData();

  if (! postData) {
    return null;
  }

  try {
    return JSON.parse(postData);
  } catch (error) {
    return null;
  }
}

async function captureFailureScreenshot(page) {
  if (! artifactDir) {
    return;
  }

  fs.mkdirSync(artifactDir, { recursive: true });
  await page.screenshot({
    fullPage: true,
    path: path.join(artifactDir, 'host-widget-smoke-failure.png'),
  }).catch(() => {});
}

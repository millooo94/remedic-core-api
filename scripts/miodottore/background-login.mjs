import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { chromium } from 'playwright';
import {
  PROVIDER_APP_URL,
  PUBLIC_PORTAL_ACCESS_ERROR,
  createProviderApiObserver,
  normalizeComparableUrl,
  safeParseUrl,
  verifyProviderAccess,
} from './provider-access.mjs';

const COOKIE_TEXT_REGEX = /(cookie|consent|privacy|gdpr|tracking|preferenze|onetrust)/i;
const COOKIE_ACTION_REGEX = /^(accetta|accetto|accept|continue|continua|agree|ok|chiudi|close)$/i;
const LOGIN_BUTTON_TEXT_REGEX = /^(accedi|login|entra|sign\s?in|log\s?in)$/i;
const LOGIN_ERROR_REGEX = /(password|credenziali|credentials|non valido|incorrect|errat|errore|invalid|failed|account)/i;

async function main() {
  const options = parseArgs(process.argv.slice(2));
  validateOptions(options);

  await fs.mkdir(options.outputDir, { recursive: true });
  await fs.mkdir(path.dirname(options.statePath), { recursive: true });
  await writeJson(options.outputDir, '00-start.json', {
    login_url: options.loginUrl,
    verify_url: options.verifyUrl,
    output_dir: options.outputDir,
    state_path: options.statePath,
    headless: options.headless,
    timeout_ms: options.timeoutMs,
    chromium_path: options.chromiumPath || null,
    started_at: new Date().toISOString(),
  });

  const browser = await chromium.launch({
    headless: options.headless,
    executablePath: options.chromiumPath || undefined,
  });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 960 },
  });
  const page = await context.newPage();
  const providerObserver = createProviderApiObserver(page);
  const networkEvents = [];

  page.on('response', async (response) => {
    const url = response.url();
    if (!/(miodottore|docplanner|auth|login|session|oauth|api)/i.test(url)) {
      return;
    }

    networkEvents.push({
      type: 'response',
      url,
      status: response.status(),
      ok: response.ok(),
      method: response.request().method(),
      content_type: response.headers()['content-type'] || null,
      captured_at: new Date().toISOString(),
    });
  });

  page.on('requestfailed', (request) => {
    const url = request.url();
    if (!/(miodottore|docplanner|auth|login|session|oauth|api)/i.test(url)) {
      return;
    }

    networkEvents.push({
      type: 'requestfailed',
      url,
      method: request.method(),
      failure: request.failure()?.errorText || null,
      captured_at: new Date().toISOString(),
    });
  });

  let result = {
    success: false,
    status: 'error',
    message: 'Collegamento MioDottore non completato.',
    final_url: '',
    page_title: '',
    saved_state: false,
    provider_api_response: null,
    api_results: [],
  };

  try {
    await page.goto(options.loginUrl, {
      waitUntil: 'domcontentloaded',
      timeout: Math.min(options.timeoutMs, 60000),
    });

    await dismissCookieBanners(page);
    await writeSnapshot(page, options.outputDir, '01-login-page');

    const usernameInput = await firstVisible(page, [
      'input[type="email"]',
      'input[name="email"]',
      'input[name="username"]',
      'input[id*="email"]',
      'input[autocomplete="username"]',
      'input[autocomplete="email"]',
    ]);
    const passwordInput = await firstVisible(page, [
      'input[type="password"]',
      'input[name="password"]',
      'input[autocomplete="current-password"]',
    ]);

    if (!usernameInput || !passwordInput) {
      throw new Error('Campi credenziali MioDottore non trovati nella pagina di login.');
    }

    await typeLikeUser(usernameInput, options.username);
    await typeLikeUser(passwordInput, options.password);
    await triggerInputEvents(usernameInput);
    await triggerInputEvents(passwordInput);
    await dismissCookieBanners(page);
    await writeSnapshot(page, options.outputDir, '02-credentials-filled');

    await submitLogin(page, passwordInput);
    await waitForLoginProcessingToSettle(page, options.loginUrl, Math.min(options.timeoutMs, 45000));
    await writeSnapshot(page, options.outputDir, '03-after-submit');

    const providerAccess = await verifyProviderAccess(page, providerObserver, {
      providerAppUrl: options.verifyUrl || PROVIDER_APP_URL,
      timeoutMs: Math.min(options.timeoutMs, 30000),
    });

    if (!providerAccess.success) {
      await writeJson(options.outputDir, '04-provider-access-failed.json', providerAccess);
      await writeSnapshot(page, options.outputDir, '04-provider-access-failed');
      throw new Error(providerAccess.message || PUBLIC_PORTAL_ACCESS_ERROR);
    }

    await context.storageState({ path: options.statePath });
    await writeSnapshot(page, options.outputDir, '04-provider-access-success');

    result = {
      success: true,
      status: 'session_valid',
      message: 'MioDottore collegato correttamente.',
      final_url: page.url(),
      page_title: await safePageTitle(page),
      saved_state: true,
      provider_api_response: providerAccess.provider_api_response,
      api_results: providerAccess.api_results,
    };
  } catch (error) {
    const loginErrors = await collectVisibleLoginErrors(page).catch(() => []);
    const captchaDetected = networkEvents.some((event) => /frcapi|captcha/i.test(event.url || ''));
    const message = captchaDetected
      ? 'MioDottore richiede una verifica anti-bot/captcha: il collegamento automatico headless non puo completarsi.'
      : (loginErrors[0]
        || (error instanceof Error ? error.message : 'Collegamento MioDottore non completato.'));

    result = {
      success: false,
      status: 'error',
      message,
      final_url: page.url(),
      page_title: await safePageTitle(page),
      saved_state: false,
      provider_api_response: null,
      api_results: [],
    };
    await writeSnapshot(page, options.outputDir, 'error').catch(() => undefined);
    process.exitCode = 1;
  } finally {
    await fs.writeFile(path.join(options.outputDir, 'network-events.json'), JSON.stringify(networkEvents, null, 2), 'utf8').catch(() => undefined);
    await fs.writeFile(path.join(options.outputDir, 'result.json'), JSON.stringify(result, null, 2), 'utf8');
    await browser.close().catch(() => undefined);
  }
}

async function waitForLoginProcessingToSettle(page, initialUrl, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  const initialComparableUrl = normalizeComparableUrl(initialUrl);

  while (Date.now() < deadline) {
    const currentComparableUrl = normalizeComparableUrl(page.url());

    if (currentComparableUrl !== initialComparableUrl) {
      await page.waitForLoadState('domcontentloaded', { timeout: 8000 }).catch(() => undefined);
      await page.waitForTimeout(1200);
      return;
    }

    const text = await page.locator('body').innerText({ timeout: 2000 }).catch(() => '');
    if (!/verifica\.\.\.|verifica|loading|caricamento/i.test(text)) {
      await page.waitForTimeout(1200);
      return;
    }

    await page.waitForTimeout(1000);
  }
}

async function submitLogin(page, passwordInput) {
  const submitControl = await findSubmitControl(page);

  if (submitControl && await isLocatorEnabled(submitControl)) {
    const baselineUrl = page.url();
    await submitControl.click({ delay: 60, timeout: 5000 }).catch(() => undefined);
    await Promise.race([
      page.waitForURL((url) => normalizeComparableUrl(url.toString()) !== normalizeComparableUrl(baselineUrl), { timeout: 7000 }).catch(() => undefined),
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 7000 }).catch(() => undefined),
      page.waitForTimeout(2500),
    ]);
    return;
  }

  await passwordInput.focus().catch(() => undefined);
  await passwordInput.press('Enter').catch(() => undefined);
  await page.waitForTimeout(2000);
}

async function dismissCookieBanners(page) {
  const containers = [
    page.locator('[id*="cookie" i]'),
    page.locator('[class*="cookie" i]'),
    page.locator('[id*="consent" i]'),
    page.locator('[class*="consent" i]'),
    page.locator('[id*="onetrust" i]'),
    page.locator('[class*="onetrust" i]'),
    page.locator('[role="dialog"]'),
    page.locator('[role="banner"]'),
  ];

  for (const container of containers) {
    const count = await container.count().catch(() => 0);
    for (let index = 0; index < count; index += 1) {
      const item = container.nth(index);
      if (!(await item.isVisible().catch(() => false))) {
        continue;
      }

      const text = await item.innerText().catch(() => '');
      if (!COOKIE_TEXT_REGEX.test(text)) {
        continue;
      }

      const buttons = item.locator('button, [role="button"], input[type="button"], input[type="submit"]');
      const buttonCount = await buttons.count().catch(() => 0);
      for (let buttonIndex = 0; buttonIndex < buttonCount; buttonIndex += 1) {
        const button = buttons.nth(buttonIndex);
        if (!(await button.isVisible().catch(() => false))) {
          continue;
        }

        const label = await locatorLabel(button);
        if (!COOKIE_ACTION_REGEX.test(label.trim())) {
          continue;
        }

        await button.click({ delay: 50, timeout: 3000 }).catch(() => undefined);
        await page.waitForTimeout(250);
        return;
      }
    }
  }
}

async function typeLikeUser(locator, value) {
  await locator.click({ timeout: 5000 }).catch(() => undefined);
  await locator.press('ControlOrMeta+A').catch(() => undefined);
  await locator.press('Delete').catch(() => undefined);
  await locator.fill('').catch(() => undefined);

  if (typeof locator.pressSequentially === 'function') {
    await locator.pressSequentially(value, { delay: 40 });
  } else {
    await locator.type(value, { delay: 40 });
  }
}

async function triggerInputEvents(locator) {
  await locator.evaluate((element) => {
    element.dispatchEvent(new Event('input', { bubbles: true }));
    element.dispatchEvent(new Event('change', { bubbles: true }));
    element.dispatchEvent(new Event('blur', { bubbles: true }));
  }).catch(() => undefined);
}

async function firstVisible(page, selectors) {
  for (const selector of selectors) {
    const locator = page.locator(selector).first();
    if (await locator.isVisible().catch(() => false)) {
      return locator;
    }
  }

  return null;
}

async function findSubmitControl(page) {
  const selectors = [
    'button[type="submit"]',
    'input[type="submit"]',
    'input[type="button"]',
    '[role="button"]',
    'button',
    'a[role="button"]',
  ];

  for (const selector of selectors) {
    const locator = page.locator(selector);
    const count = await locator.count().catch(() => 0);
    for (let index = 0; index < count; index += 1) {
      const candidate = locator.nth(index);
      if (!(await candidate.isVisible().catch(() => false))) {
        continue;
      }

      const label = await locatorLabel(candidate);
      if (LOGIN_BUTTON_TEXT_REGEX.test(label)) {
        return candidate;
      }
    }
  }

  const genericSubmit = page.locator('button[type="submit"], input[type="submit"]').first();
  if (await genericSubmit.isVisible().catch(() => false)) {
    return genericSubmit;
  }

  return null;
}

async function isLocatorEnabled(locator) {
  return locator.isEnabled().catch(() => false);
}

async function locatorLabel(locator) {
  return (await locator.innerText().catch(() => ''))
    || (await locator.inputValue().catch(() => ''))
    || (await locator.getAttribute('aria-label').catch(() => ''))
    || '';
}

async function collectVisibleLoginErrors(page) {
  const selectors = [
    '[role="alert"]',
    '.error',
    '.alert',
    '.notification',
    '[data-testid*="error"]',
    '[class*="error" i]',
    '[class*="alert" i]',
  ];
  const messages = [];

  for (const selector of selectors) {
    const locator = page.locator(selector);
    const count = await locator.count().catch(() => 0);
    for (let index = 0; index < count; index += 1) {
      const item = locator.nth(index);
      if (!(await item.isVisible().catch(() => false))) {
        continue;
      }

      const text = (await item.innerText().catch(() => '')).trim();
      if (text !== '' && LOGIN_ERROR_REGEX.test(text)) {
        messages.push(text);
      }
    }
  }

  return [...new Set(messages)];
}

async function writeSnapshot(page, outputDir, baseName) {
  await page.screenshot({ path: path.join(outputDir, `${baseName}.png`), fullPage: true }).catch(() => undefined);
  const text = [
    `URL: ${page.url()}`,
    `Titolo: ${await safePageTitle(page)}`,
    '',
    'Testo visibile:',
    await getVisibleText(page),
  ].join('\n');
  await fs.writeFile(path.join(outputDir, `${baseName}.txt`), text, 'utf8');
}

async function getVisibleText(page) {
  return (await page.locator('body').innerText().catch(() => '')).trim().slice(0, 6000);
}

async function safePageTitle(page) {
  return page.title().catch(() => '');
}

async function writeJson(outputDir, fileName, payload) {
  await fs.writeFile(path.join(outputDir, fileName), JSON.stringify(payload, null, 2), 'utf8');
}

function parseArgs(args) {
  const options = {
    loginUrl: '',
    verifyUrl: '',
    statePath: '',
    outputDir: '',
    headless: true,
    timeoutMs: 120000,
    chromiumPath: '',
    username: process.env.MIODOTTORE_BG_USERNAME ?? '',
    password: process.env.MIODOTTORE_BG_PASSWORD ?? '',
  };

  for (let index = 0; index < args.length; index += 1) {
    const arg = args[index];
    const next = args[index + 1];

    switch (arg) {
      case '--login-url':
        options.loginUrl = next ?? '';
        index += 1;
        break;
      case '--verify-url':
        options.verifyUrl = next ?? '';
        index += 1;
        break;
      case '--state-path':
        options.statePath = next ?? '';
        index += 1;
        break;
      case '--output-dir':
        options.outputDir = next ?? '';
        index += 1;
        break;
      case '--headless':
        options.headless = (next ?? 'true').toLowerCase() === 'true';
        index += 1;
        break;
      case '--timeout-ms':
        options.timeoutMs = Number.parseInt(next ?? '120000', 10);
        index += 1;
        break;
      case '--chromium-path':
        options.chromiumPath = next ?? '';
        index += 1;
        break;
      default:
        break;
    }
  }

  return options;
}

function validateOptions(options) {
  const missing = [];
  if (!options.loginUrl) missing.push('loginUrl');
  if (!options.statePath) missing.push('statePath');
  if (!options.outputDir) missing.push('outputDir');
  if (!options.username) missing.push('username');
  if (!options.password) missing.push('password');

  if (missing.length) {
    throw new Error(`Configurazione background login MioDottore incompleta: mancano ${missing.join(', ')}.`);
  }
}

main().catch((error) => {
  console.error('[miodottore-background-login] ' + (error instanceof Error ? error.stack ?? error.message : String(error)));
  process.exit(1);
});

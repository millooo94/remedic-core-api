import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { chromium } from 'playwright';

const COOKIE_TEXT_REGEX = /(cookie|consent|privacy|gdpr|tracking|preferenze)/i;
const COOKIE_ACTION_REGEX = /^(accetta|accetto|accept|continue|continua|reject|rifiuta|chiudi|close)$/i;
const LOGIN_BUTTON_TEXT_REGEX = /(accedi|login|entra|continua|sign in|log in)/i;
const CAPTCHA_REGEX = /(captcha|recaptcha|non sono un robot|i am not a robot)/i;
const TWO_FACTOR_REGEX = /(2fa|two-factor|two factor|due fattori|codice di verifica|verification code|one-time password|otp|authenticator)/i;

async function main() {
  const options = parseArgs(process.argv.slice(2));
  validateOptions(options);

  await fs.mkdir(options.outputDir, { recursive: true });

  const browser = await chromium.launch({
    headless: options.headless,
    slowMo: options.headless ? 0 : options.slowMoMs,
    executablePath: options.chromiumPath || undefined,
  });

  const context = await browser.newContext({
    viewport: { width: 1440, height: 960 },
  });

  const page = await context.newPage();
  let loginSucceeded = false;

  await context.tracing.start({ screenshots: true, snapshots: true, sources: true });

  try {
    log(`Apertura login MioDottore: ${options.loginUrl}`);
    await page.goto(options.loginUrl, { waitUntil: 'domcontentloaded', timeout: options.timeoutMs });
    await page.waitForLoadState('networkidle', { timeout: options.timeoutMs }).catch(() => undefined);

    const loginDiagnostics = await performLogin(page, options);
    loginSucceeded = true;

    await page.screenshot({ path: path.join(options.outputDir, 'login-success.png'), fullPage: true });
    log('Login riuscito.');
    log(`Segnale post-login: ${loginDiagnostics.lastSubmitSignal || 'nessuno'}`);

    log(`Navigazione alla pagina Orari: ${options.targetUrl}`);
    await page.goto(options.targetUrl, { waitUntil: 'domcontentloaded', timeout: options.timeoutMs });
    await page.waitForLoadState('networkidle', { timeout: options.timeoutMs }).catch(() => undefined);

    const title = await safePageTitle(page);
    const finalUrl = page.url();
    const pageHtml = await page.content();
    const mainText = await getVisibleText(page);

    await page.screenshot({ path: path.join(options.outputDir, 'orari-page.png'), fullPage: true });
    await fs.writeFile(path.join(options.outputDir, 'orari-page.html'), pageHtml, 'utf8');
    await fs.writeFile(path.join(options.outputDir, 'orari-page.txt'), mainText, 'utf8');
    await fs.writeFile(path.join(options.outputDir, 'debug-metadata.json'), JSON.stringify({
      login_succeeded: loginSucceeded,
      final_url: finalUrl,
      page_title: title,
      text_excerpt: mainText,
      target_url: options.targetUrl,
      generated_at: new Date().toISOString(),
      login_diagnostics: loginDiagnostics,
    }, null, 2), 'utf8');

    log(`URL finale: ${finalUrl}`);
    log(`Titolo pagina: ${title}`);
    log(`Testo principale trovato:\n${mainText || '[vuoto]'}`);
  } catch (error) {
    await saveErrorArtifacts(page, options.outputDir, error?.loginDiagnostics ?? null);
    log(`Errore Playwright: ${error instanceof Error ? error.stack ?? error.message : String(error)}`, 'stderr');
    process.exitCode = 1;
  } finally {
    await context.tracing.stop({ path: path.join(options.outputDir, 'trace.zip') }).catch(() => undefined);
    await browser.close().catch(() => undefined);
  }
}

async function performLogin(page, options) {
  const diagnostics = {
    cookieBannerDismissed: false,
    cookieBannerActions: [],
    submitControlFound: false,
    submitControlLabel: '',
    submitButtonWasDisabled: null,
    submitButtonIsDisabled: null,
    submitClicked: false,
    enterTried: false,
    clickError: null,
    lastSubmitSignal: null,
    finalUrl: '',
    finalTitle: '',
    finalTextExcerpt: '',
    loginErrorMessages: [],
    loginFormVisible: false,
    captchaDetected: false,
    twoFactorDetected: false,
    activeCookieBanner: false,
    blockingCause: null,
  };

  const usernameInput = await firstVisible(page, [
    'input[type="email"]',
    'input[name="email"]',
    'input[name="username"]',
    'input[id*="email"]',
    'input[autocomplete="username"]',
    'input[autocomplete="email"]',
  ]);
  if (!usernameInput) {
    throw createLoginFailure('Campo username/email non trovato nella pagina di login.', diagnostics);
  }

  const passwordInput = await firstVisible(page, [
    'input[type="password"]',
    'input[name="password"]',
    'input[autocomplete="current-password"]',
  ]);
  if (!passwordInput) {
    throw createLoginFailure('Campo password non trovato nella pagina di login.', diagnostics);
  }

  await writeStepSnapshot(page, options.outputDir, '01-login-page', {
    note: 'Pagina di login caricata.',
  });

  await dismissCookieBanners(page, diagnostics);

  await typeLikeUser(usernameInput, options.username);
  await typeLikeUser(passwordInput, options.password);
  await triggerInputEvents(usernameInput);
  await triggerInputEvents(passwordInput);
  await passwordInput.blur().catch(() => undefined);
  await page.waitForTimeout(250);
  await dismissCookieBanners(page, diagnostics);

  let submitControl = await findSubmitControl(page);
  diagnostics.submitControlFound = !!submitControl;
  diagnostics.submitControlLabel = submitControl ? await locatorLabel(submitControl) : '';
  diagnostics.submitButtonWasDisabled = submitControl ? !(await isLocatorEnabled(submitControl)) : null;

  await writeStepSnapshot(page, options.outputDir, '02-after-fill-credentials', {
    submit_control_found: diagnostics.submitControlFound,
    submit_control_label: diagnostics.submitControlLabel,
    submit_button_disabled: diagnostics.submitButtonWasDisabled,
  });

  const baselineUrl = page.url();
  await dismissCookieBanners(page, diagnostics);

  if (submitControl) {
    diagnostics.submitButtonIsDisabled = !(await isLocatorEnabled(submitControl));
    if (!diagnostics.submitButtonIsDisabled) {
      try {
        diagnostics.submitClicked = true;
        const waitForSignal = waitForPostSubmitSignal(page, options, baselineUrl);
        await submitControl.scrollIntoViewIfNeeded().catch(() => undefined);
        await submitControl.click({ delay: 75, timeout: 5000 });
        diagnostics.lastSubmitSignal = await waitForSignal;
      } catch (error) {
        diagnostics.clickError = error instanceof Error ? error.message : String(error);
      }
    }
  }

  await writeStepSnapshot(page, options.outputDir, '03-after-submit-click', {
    submit_control_found: diagnostics.submitControlFound,
    submit_control_label: diagnostics.submitControlLabel,
    submit_button_disabled: diagnostics.submitButtonIsDisabled,
    submit_clicked: diagnostics.submitClicked,
    click_error: diagnostics.clickError,
    submit_signal: diagnostics.lastSubmitSignal,
  });

  let assessment = await assessLoginState(page, baselineUrl, diagnostics);

  if (!assessment.success) {
    diagnostics.enterTried = true;
    const waitForSignal = waitForPostSubmitSignal(page, options, baselineUrl);
    await passwordInput.focus().catch(() => undefined);
    await passwordInput.press('Enter').catch(() => undefined);
    diagnostics.lastSubmitSignal = await waitForSignal;
  }

  await writeStepSnapshot(page, options.outputDir, '04-after-enter-submit', {
    enter_tried: diagnostics.enterTried,
    submit_signal: diagnostics.lastSubmitSignal,
  });

  assessment = await assessLoginState(page, baselineUrl, diagnostics);

  await writeStepSnapshot(page, options.outputDir, '05-final-state', diagnostics);

  if (!assessment.success) {
    throw createLoginFailure(buildFailureMessage(diagnostics), diagnostics);
  }

  return diagnostics;
}

async function dismissCookieBanners(page, diagnostics) {
  const containers = [
    page.locator('[id*="cookie" i]'),
    page.locator('[class*="cookie" i]'),
    page.locator('[id*="consent" i]'),
    page.locator('[class*="consent" i]'),
    page.locator('[id*="onetrust" i]'),
    page.locator('[class*="onetrust" i]'),
    page.locator('[aria-label*="cookie" i]'),
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

      const containerText = await item.innerText().catch(() => '');
      if (!COOKIE_TEXT_REGEX.test(containerText)) {
        continue;
      }

      const actionButtons = item.locator('button, [role="button"], input[type="button"], input[type="submit"]');
      const buttonCount = await actionButtons.count().catch(() => 0);
      for (let buttonIndex = 0; buttonIndex < buttonCount; buttonIndex += 1) {
        const button = actionButtons.nth(buttonIndex);
        if (!(await button.isVisible().catch(() => false))) {
          continue;
        }

        const label = await locatorLabel(button);
        if (!COOKIE_ACTION_REGEX.test(label.trim())) {
          continue;
        }

        await button.click({ delay: 50, timeout: 3000 }).catch(() => undefined);
        diagnostics.cookieBannerDismissed = true;
        diagnostics.cookieBannerActions.push(label.trim() || '[senza label]');
        await page.waitForTimeout(300);
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
    await locator.pressSequentially(value, { delay: 80 });
  } else {
    await locator.type(value, { delay: 80 });
  }
}

async function triggerInputEvents(locator) {
  await locator.evaluate((element) => {
    element.dispatchEvent(new Event('input', { bubbles: true }));
    element.dispatchEvent(new Event('change', { bubbles: true }));
    element.dispatchEvent(new Event('blur', { bubbles: true }));
  }).catch(() => undefined);
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

async function waitForPostSubmitSignal(page, options, baselineUrl) {
  const signal = await Promise.race([
    page.waitForURL((url) => normalizeUrl(url.toString()) !== normalizeUrl(baselineUrl), { timeout: 7000 })
      .then(() => 'url-changed')
      .catch(() => null),
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 7000 })
      .then(() => 'navigation')
      .catch(() => null),
    waitForPostLoginIndicator(page, 7000)
      .then(() => 'post-login-indicator')
      .catch(() => null),
    page.waitForTimeout(2500).then(() => 'no-strong-signal'),
  ]);

  await page.waitForLoadState('networkidle', { timeout: Math.min(options.timeoutMs, 7000) }).catch(() => undefined);
  await page.waitForTimeout(600);

  return signal;
}

async function waitForPostLoginIndicator(page, timeoutMs) {
  const selectors = [
    'a[href*="logout" i]',
    'button:has-text("Logout")',
    'a[href*="agenda" i]',
    'a[href*="dashboard" i]',
    'a[href*="orari" i]',
    'text=/agenda/i',
    'text=/dashboard/i',
    'text=/orari/i',
  ];

  const startedAt = Date.now();
  while (Date.now() - startedAt < timeoutMs) {
    for (const selector of selectors) {
      const locator = page.locator(selector).first();
      if (await locator.isVisible().catch(() => false)) {
        return selector;
      }
    }

    await page.waitForTimeout(200);
  }

  throw new Error('Nessun indicatore post-login rilevato.');
}

async function assessLoginState(page, baselineUrl, diagnostics) {
  const finalText = await getVisibleText(page);
  const loginFormVisible = await page.locator('input[type="password"]').first().isVisible().catch(() => false);
  const postLoginIndicatorVisible = await hasVisiblePostLoginIndicator(page);
  const activeCookieBanner = await hasActiveCookieBanner(page);
  const loginErrorMessages = await collectLoginErrors(page);
  const finalUrl = page.url();
  const finalTitle = await safePageTitle(page);
  const captchaDetected = CAPTCHA_REGEX.test(finalText);
  const twoFactorDetected = TWO_FACTOR_REGEX.test(finalText);
  const urlChanged = normalizeUrl(finalUrl) !== normalizeUrl(baselineUrl);

  diagnostics.finalUrl = finalUrl;
  diagnostics.finalTitle = finalTitle;
  diagnostics.finalTextExcerpt = finalText;
  diagnostics.loginErrorMessages = loginErrorMessages;
  diagnostics.loginFormVisible = loginFormVisible;
  diagnostics.captchaDetected = captchaDetected;
  diagnostics.twoFactorDetected = twoFactorDetected;
  diagnostics.activeCookieBanner = activeCookieBanner;
  diagnostics.blockingCause = detectBlockingCause(diagnostics);

  const success = !captchaDetected
    && !twoFactorDetected
    && (urlChanged || postLoginIndicatorVisible || (!loginFormVisible && loginErrorMessages.length === 0));

  return { success };
}

async function hasVisiblePostLoginIndicator(page) {
  const selectors = [
    'a[href*="logout" i]',
    'button:has-text("Logout")',
    'a[href*="agenda" i]',
    'a[href*="dashboard" i]',
    'a[href*="orari" i]',
  ];

  for (const selector of selectors) {
    const locator = page.locator(selector).first();
    if (await locator.isVisible().catch(() => false)) {
      return true;
    }
  }

  return false;
}

async function hasActiveCookieBanner(page) {
  const selectors = [
    '[id*="cookie" i]',
    '[class*="cookie" i]',
    '[id*="consent" i]',
    '[class*="consent" i]',
    '[id*="onetrust" i]',
    '[class*="onetrust" i]',
    '[role="dialog"]',
    '[role="banner"]',
  ];

  for (const selector of selectors) {
    const locator = page.locator(selector);
    const count = await locator.count().catch(() => 0);
    for (let index = 0; index < count; index += 1) {
      const item = locator.nth(index);
      if (!(await item.isVisible().catch(() => false))) {
        continue;
      }

      const text = await item.innerText().catch(() => '');
      if (COOKIE_TEXT_REGEX.test(text)) {
        return true;
      }
    }
  }

  return false;
}

async function collectLoginErrors(page) {
  const messages = new Set();
  const selectors = [
    '[role="alert"]',
    '[aria-live="assertive"]',
    '[aria-live="polite"]',
    '.error',
    '.errors',
    '.alert',
    '.alert-danger',
    '.notification',
    '.form-error',
    '.field-error',
  ];

  for (const selector of selectors) {
    const locator = page.locator(selector);
    const count = await locator.count().catch(() => 0);
    for (let index = 0; index < count; index += 1) {
      const item = locator.nth(index);
      if (!(await item.isVisible().catch(() => false))) {
        continue;
      }

      const text = (await item.innerText().catch(() => '')).trim();
      if (text) {
        messages.add(text);
      }
    }
  }

  return Array.from(messages).slice(0, 10);
}

function detectBlockingCause(diagnostics) {
  if (diagnostics.captchaDetected) {
    return 'captcha';
  }

  if (diagnostics.twoFactorDetected) {
    return '2fa';
  }

  if (diagnostics.activeCookieBanner) {
    return 'cookie-banner';
  }

  if (diagnostics.submitButtonIsDisabled || diagnostics.submitButtonWasDisabled) {
    return 'submit-disabled';
  }

  if (diagnostics.loginErrorMessages.length > 0) {
    return 'login-error-message';
  }

  if (diagnostics.loginFormVisible) {
    return 'still-on-login-form';
  }

  return 'unknown';
}

function buildFailureMessage(diagnostics) {
  return [
    'Login MioDottore non completato.',
    `URL finale: ${diagnostics.finalUrl || '[sconosciuto]'}`,
    `Titolo pagina: ${diagnostics.finalTitle || '[sconosciuto]'}`,
    `Submit button disabled: ${formatBooleanValue(diagnostics.submitButtonIsDisabled)}`,
    `Submit cliccato: ${formatBooleanValue(diagnostics.submitClicked)}`,
    `Enter provato: ${formatBooleanValue(diagnostics.enterTried)}`,
    `Captcha rilevato: ${formatBooleanValue(diagnostics.captchaDetected)}`,
    `2FA rilevato: ${formatBooleanValue(diagnostics.twoFactorDetected)}`,
    `Cookie banner attivo: ${formatBooleanValue(diagnostics.activeCookieBanner)}`,
    `Causa probabile: ${diagnostics.blockingCause || 'unknown'}`,
    diagnostics.loginErrorMessages.length
      ? `Messaggi errore: ${diagnostics.loginErrorMessages.join(' | ')}`
      : 'Messaggi errore: nessuno rilevato',
    `Testo visibile: ${diagnostics.finalTextExcerpt || '[vuoto]'}`,
  ].join('\n');
}

function createLoginFailure(message, diagnostics) {
  const error = new Error(message);
  error.loginDiagnostics = diagnostics;
  return error;
}

async function writeStepSnapshot(page, outputDir, baseName, extra = {}) {
  const screenshotPath = path.join(outputDir, `${baseName}.png`);
  const textPath = path.join(outputDir, `${baseName}.txt`);
  const title = await safePageTitle(page);
  const url = page.url();
  const visibleText = await getVisibleText(page);

  const lines = [
    `URL: ${url}`,
    `Titolo: ${title}`,
  ];

  for (const [key, value] of Object.entries(extra)) {
    lines.push(`${key}: ${formatDebugValue(value)}`);
  }

  lines.push('', 'Testo visibile:', visibleText || '[vuoto]');

  await page.screenshot({ path: screenshotPath, fullPage: true }).catch(() => undefined);
  await fs.writeFile(textPath, lines.join('\n'), 'utf8');
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

async function locatorLabel(locator) {
  const value = await locator.inputValue().catch(() => '');
  if (value?.trim()) {
    return value.trim();
  }

  const text = await locator.innerText().catch(() => '');
  if (text?.trim()) {
    return text.trim();
  }

  const ariaLabel = await locator.getAttribute('aria-label').catch(() => '');
  if (ariaLabel?.trim()) {
    return ariaLabel.trim();
  }

  return '';
}

async function isLocatorEnabled(locator) {
  return locator.isEnabled().catch(() => false);
}

async function getVisibleText(page) {
  const bodyText = await page.locator('body').innerText().catch(() => '');
  return bodyText.trim().slice(0, 8000);
}

async function safePageTitle(page) {
  return page.title().catch(() => '');
}

async function saveErrorArtifacts(page, outputDir, diagnostics) {
  try {
    await page.screenshot({ path: path.join(outputDir, 'error.png'), fullPage: true });
  } catch {
    // ignore screenshot errors during cleanup
  }

  try {
    const html = await page.content();
    await fs.writeFile(path.join(outputDir, 'error-page.html'), html, 'utf8');
  } catch {
    // ignore html dump errors during cleanup
  }

  try {
    const visibleText = await getVisibleText(page);
    const lines = [];
    if (diagnostics) {
      lines.push('Diagnostica login:');
      lines.push(JSON.stringify(diagnostics, null, 2));
      lines.push('');
    }
    lines.push('Testo visibile finale:');
    lines.push(visibleText || '[vuoto]');
    await fs.writeFile(path.join(outputDir, 'error.txt'), lines.join('\n'), 'utf8');
  } catch {
    // ignore text dump errors during cleanup
  }
}

function normalizeUrl(value) {
  return String(value || '').trim().replace(/\/+$/, '');
}

function formatBooleanValue(value) {
  if (value === null || value === undefined) {
    return 'n/d';
  }

  return value ? 'si' : 'no';
}

function formatDebugValue(value) {
  if (typeof value === 'string') {
    return value;
  }

  if (typeof value === 'boolean') {
    return value ? 'true' : 'false';
  }

  if (value === null || value === undefined) {
    return 'null';
  }

  return JSON.stringify(value);
}

function parseArgs(args) {
  const options = {
    loginUrl: '',
    username: '',
    password: '',
    targetUrl: '',
    outputDir: '',
    headless: false,
    timeoutMs: 90000,
    slowMoMs: 150,
    chromiumPath: '',
  };

  for (let index = 0; index < args.length; index += 1) {
    const arg = args[index];
    const next = args[index + 1];

    switch (arg) {
      case '--login-url':
        options.loginUrl = next ?? '';
        index += 1;
        break;
      case '--username':
        options.username = next ?? '';
        index += 1;
        break;
      case '--password':
        options.password = next ?? '';
        index += 1;
        break;
      case '--target-url':
        options.targetUrl = next ?? '';
        index += 1;
        break;
      case '--output-dir':
        options.outputDir = next ?? '';
        index += 1;
        break;
      case '--headless':
        options.headless = (next ?? 'false').toLowerCase() === 'true';
        index += 1;
        break;
      case '--timeout-ms':
        options.timeoutMs = Number.parseInt(next ?? '90000', 10);
        index += 1;
        break;
      case '--slowmo-ms':
        options.slowMoMs = Number.parseInt(next ?? '150', 10);
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
  if (!options.username) missing.push('username');
  if (!options.password) missing.push('password');
  if (!options.targetUrl) missing.push('targetUrl');
  if (!options.outputDir) missing.push('outputDir');

  if (missing.length > 0) {
    throw new Error(`Configurazione Playwright incompleta: mancano ${missing.join(', ')}.`);
  }
}

function log(message, stream = 'stdout') {
  const prefix = '[miodottore-debug]';
  if (stream === 'stderr') {
    console.error(`${prefix} ${message}`);
    return;
  }

  console.log(`${prefix} ${message}`);
}

main().catch((error) => {
  log(error instanceof Error ? error.stack ?? error.message : String(error), 'stderr');
  process.exit(1);
});

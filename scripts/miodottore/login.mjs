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

const COOKIE_TEXT_REGEX = /(cookie|consent|privacy|gdpr|tracking|preferenze)/i;
const COOKIE_ACTION_REGEX = /^(accetta|accetto|accept|continue|continua|reject|rifiuta|chiudi|close)$/i;
const LOGIN_PATH_REGEX = /(login|accedi|sign-?in|log-?in|entra)/i;
const LOGIN_TEXT_REGEX = /(effettua il login al tuo account|tramite il modulo di accesso|hai dimenticato la password|login)/i;
const PUBLIC_HOMEPAGE_TEXT_REGEX = /(prenota la tua visita online|trova un dottore e prenota la tua visita online|miodottore\.it - trova un dottore)/i;
const STATE_WRITE_INTERVAL_MS = 15000;
const POLL_INTERVAL_MS = 1500;

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
    slowmo_ms: options.slowMoMs,
    chromium_path: options.chromiumPath || null,
    started_at: new Date().toISOString(),
  });

  const browser = await chromium.launch({
    headless: options.headless,
    slowMo: options.headless ? 0 : options.slowMoMs,
    executablePath: options.chromiumPath || undefined,
    args: [
      '--window-size=1400,900',
      '--window-position=80,80',
      '--start-maximized',
    ],
  });
  await writeJson(options.outputDir, '01-browser-launched.json', {
    launched_at: new Date().toISOString(),
    headless: options.headless,
    browser_type: browser.browserType().name(),
    chromium_path: options.chromiumPath || null,
  });

  const context = await browser.newContext({
    viewport: { width: 1400, height: 900 },
  });
  const page = await context.newPage();
  const providerObserver = createProviderApiObserver(page);
  await page.bringToFront().catch(() => undefined);
  await writeJson(options.outputDir, '02-page-created.json', {
    created_at: new Date().toISOString(),
    page_count: context.pages().length,
    initial_url: page.url(),
  });

  let result = {
    success: false,
    message: 'Accesso MioDottore non completato.',
    final_url: '',
    page_title: '',
    saved_state: false,
    status: 'error',
    analysis_status: null,
    next_action: null,
  };

  try {
    log(`Apertura pagina di accesso MioDottore: ${options.loginUrl}`);
    await page.goto(options.loginUrl, {
      waitUntil: 'domcontentloaded',
      timeout: Math.min(options.timeoutMs, 60000),
    });
    await page.bringToFront().catch(() => undefined);
    await dismissCookieBanners(page);

    await writeSnapshot(page, options.outputDir, '03-login-url-opened');

    log('Completa ora l accesso MioDottore nella finestra aperta. Il sistema osservera la sessione e salvera il collegamento senza guidare la navigazione.');
    const finalAnalysis = await waitForAuthenticatedState(page, context, options, providerObserver);

    await context.storageState({ path: options.statePath });
    await writeSnapshot(page, options.outputDir, '03-access-completed');

    result = {
      success: true,
      message: 'Accesso MioDottore provider verificato e sessione salvata.',
      final_url: page.url(),
      page_title: await safePageTitle(page),
      saved_state: true,
      status: 'session_valid',
      analysis_status: finalAnalysis.analysis_status,
      next_action: 'verify_saved_session',
      provider_api_response: finalAnalysis.provider_access?.provider_api_response ?? null,
      api_results: finalAnalysis.provider_access?.api_results ?? [],
    };
    log(result.message);
  } catch (error) {
    const analysis = await analyzeAccessState(page, context, options.loginUrl, { includeVisibleText: true }).catch(() => null);
    result = {
      success: false,
      message: error instanceof Error ? error.message : 'Accesso MioDottore non completato.',
      final_url: page.url(),
      page_title: await safePageTitle(page),
      saved_state: false,
      status: 'error',
      analysis_status: analysis?.analysis_status ?? 'error',
      next_action: analysis?.next_action ?? 'retry_connection',
    };
    await writeSnapshot(page, options.outputDir, 'error-state').catch(() => undefined);
    log(result.message, 'stderr');
    process.exitCode = 1;
  } finally {
    await fs.writeFile(path.join(options.outputDir, 'result.json'), JSON.stringify(result, null, 2), 'utf8');
    await browser.close().catch(() => undefined);
  }
}

async function waitForAuthenticatedState(page, context, options, providerObserver) {
  const startedAt = Date.now();
  let lastStateWriteAt = 0;
  let lastAnalysisStatus = '';
  let lastAnalysis = await analyzeAccessState(page, options.loginUrl, { includeVisibleText: true });

  while (Date.now() - startedAt < options.timeoutMs) {
    const now = Date.now();
    const shouldWriteVerboseState = now - lastStateWriteAt >= STATE_WRITE_INTERVAL_MS;
    const analysis = await analyzeAccessState(page, options.loginUrl, {
      includeVisibleText: shouldWriteVerboseState,
    });
    lastAnalysis = analysis;

    if (shouldWriteVerboseState || analysis.analysis_status !== lastAnalysisStatus) {
      await writeCurrentState(page, options.outputDir, '04-current-state.json', {
        elapsed_seconds: Math.floor((now - startedAt) / 1000),
        ...analysis,
      });
      lastStateWriteAt = now;
    }

    if (analysis.analysis_status !== lastAnalysisStatus) {
      log(`Stato osservato: ${analysis.analysis_status}`);
      lastAnalysisStatus = analysis.analysis_status;
    }

    if (analysis.analysis_status === 'internal_app_reached'
      || analysis.analysis_status === 'authenticated_session_detected'
      || analysis.analysis_status === 'public_homepage_detected') {
      if (analysis.analysis_status === 'internal_app_reached' || analysis.analysis_status === 'authenticated_session_detected') {
        await page.waitForTimeout(3500);
      }

      const providerAccess = await verifyProviderAccess(page, providerObserver, {
        providerAppUrl: options.verifyUrl || PROVIDER_APP_URL,
        timeoutMs: Math.min(options.timeoutMs, 45000),
      });

      if (providerAccess.success) {
        await writeCurrentState(page, options.outputDir, '04-current-state.json', {
          elapsed_seconds: Math.floor((Date.now() - startedAt) / 1000),
          ...analysis,
          provider_access: providerAccess,
        });

        return {
          ...analysis,
          analysis_status: 'provider_api_verified',
          next_action: 'save_storage_state',
          provider_access: providerAccess,
        };
      }

      if (analysis.analysis_status === 'public_homepage_detected' || providerAccess.public_homepage_visible) {
        await writeCurrentState(page, options.outputDir, '05-public-homepage-detected.json', {
          elapsed_seconds: Math.floor((Date.now() - startedAt) / 1000),
          ...analysis,
          provider_access: providerAccess,
        });
        await writeSnapshot(page, options.outputDir, '05-public-homepage-detected');
      }

      throw new Error(providerAccess.message || PUBLIC_PORTAL_ACCESS_ERROR);
    }

    await page.waitForTimeout(POLL_INTERVAL_MS);
  }

  if (lastAnalysis.analysis_status === 'login_page_visible') {
    throw new Error('Il login MioDottore era aperto, ma non e stato completato entro il tempo previsto. Riprova e completa l accesso nella finestra Chrome.');
  }

  if (lastAnalysis.analysis_status === 'app_selection_required') {
    throw new Error('La selezione del profilo MioDottore non e stata completata entro il tempo previsto. Riprova e completa la scelta nella finestra Chrome.');
  }

  throw new Error('Accesso MioDottore non completato entro il tempo previsto. Riprova.');
}

async function analyzeAccessState(page, loginUrl, { includeVisibleText = false } = {}) {
  const currentUrl = page.url();
  const current = safeParseUrl(currentUrl);
  const login = safeParseUrl(loginUrl);
  const host = current?.host ?? '';
  const pathname = current?.pathname?.toLowerCase() ?? '';
  const loginHost = login?.host ?? '';
  const loginPathname = login?.pathname?.toLowerCase() ?? '';
  const title = await safePageTitle(page);
  const visibleText = includeVisibleText ? await getVisibleText(page) : '';
  const hasPasswordInput = await page.locator('input[type="password"]').first().isVisible().catch(() => false);
  const hasEmailInput = await firstVisible(page, [
    'input[type="email"]',
    'input[name="email"]',
    'input[name="username"]',
    'input[id*="email"]',
    'input[autocomplete="username"]',
    'input[autocomplete="email"]',
  ]);
  const hasLoginInput = await firstVisible(page, [
    'input[type="password"]',
    'input[type="email"]',
    'input[name="email"]',
    'input[name="username"]',
    'input[name="password"]',
  ]);
  const isAppSelectionPage = host === 'l.miodottore.it' && pathname.startsWith('/apps');
  const isInternalApp = host === 'docplanner.miodottore.it';
  const sameLoginLocation = normalizeComparableUrl(currentUrl) === normalizeComparableUrl(loginUrl);
  const looksLikeLoginPath = LOGIN_PATH_REGEX.test(pathname) || pathname === loginPathname || pathname === '/' || pathname === '';
  const textSuggestsLogin = LOGIN_TEXT_REGEX.test(title) || (visibleText !== '' && LOGIN_TEXT_REGEX.test(visibleText));
  const isLoginPage = hasPasswordInput || hasEmailInput || hasLoginInput || textSuggestsLogin || sameLoginLocation || (host === loginHost && looksLikeLoginPath && !isAppSelectionPage);
  const publicHomepageVisible = (
    (host === 'www.miodottore.it' || host === 'miodottore.it') &&
    (pathname === '/' || pathname === '')
  ) || PUBLIC_HOMEPAGE_TEXT_REGEX.test(title) || (visibleText !== '' && PUBLIC_HOMEPAGE_TEXT_REGEX.test(visibleText));

  if (isInternalApp && !isLoginPage && !isAppSelectionPage && !publicHomepageVisible) {
    return buildAnalysis({
      url: currentUrl,
      title,
      loginVisible: false,
      internalAppVisible: true,
      publicHomepageVisible: false,
      analysisStatus: 'internal_app_reached',
      nextAction: 'verify_provider_session',
    });
  }

  if (isAppSelectionPage && !isLoginPage) {
    return buildAnalysis({
      url: currentUrl,
      title,
      loginVisible: false,
      internalAppVisible: false,
      publicHomepageVisible: false,
      analysisStatus: 'app_selection_required',
      nextAction: 'wait_for_user_profile_selection',
    });
  }

  if (publicHomepageVisible && !isLoginPage) {
    return buildAnalysis({
      url: currentUrl,
      title,
      loginVisible: false,
      internalAppVisible: false,
      publicHomepageVisible: true,
      analysisStatus: 'public_homepage_detected',
      nextAction: 'verify_provider_session',
    });
  }

  if (!isLoginPage && !publicHomepageVisible) {
    return buildAnalysis({
      url: currentUrl,
      title,
      loginVisible: false,
      internalAppVisible: isInternalApp,
      publicHomepageVisible,
      analysisStatus: 'authenticated_session_detected',
      nextAction: 'verify_provider_session',
    });
  }

  if (isLoginPage) {
    return buildAnalysis({
      url: currentUrl,
      title,
      loginVisible: true,
      internalAppVisible: false,
      publicHomepageVisible: false,
      analysisStatus: 'login_page_visible',
      nextAction: 'wait_for_user_login',
    });
  }

  return buildAnalysis({
    url: currentUrl,
    title,
    loginVisible: false,
    internalAppVisible: false,
    publicHomepageVisible: false,
    analysisStatus: 'navigation_pending',
    nextAction: 'wait_for_user_navigation',
  });
}

async function dismissCookieBanners(page) {
  const containers = [
    page.locator('[id*="cookie" i]'),
    page.locator('[class*="cookie" i]'),
    page.locator('[id*="consent" i]'),
    page.locator('[class*="consent" i]'),
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

      const buttons = item.locator('button, [role="button"], input[type="submit"], input[type="button"]');
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
        await page.waitForTimeout(300);
        return;
      }
    }
  }
}

async function firstVisible(page, selectors) {
  for (const selector of selectors) {
    const locator = page.locator(selector).first();
    if (await locator.isVisible().catch(() => false)) {
      return true;
    }
  }

  return false;
}

async function writeCurrentState(page, outputDir, fileName, extra = {}) {
  const payload = {
    url: page.url(),
    title: await safePageTitle(page),
    captured_at: new Date().toISOString(),
    ...extra,
  };

  if (!Object.prototype.hasOwnProperty.call(payload, 'visible_text')) {
    payload.visible_text = await getVisibleText(page);
  }

  await writeJson(outputDir, fileName, payload);
}

function buildAnalysis({
  url,
  title,
  loginVisible,
  internalAppVisible,
  publicHomepageVisible,
  analysisStatus,
  nextAction,
}) {
  return {
    url,
    title,
    login_visible: loginVisible,
    internal_app_visible: internalAppVisible,
    public_homepage_visible: publicHomepageVisible,
    analysis_status: analysisStatus,
    next_action: nextAction,
  };
}

async function locatorLabel(locator) {
  return (await locator.innerText().catch(() => ''))
    || (await locator.inputValue().catch(() => ''))
    || (await locator.getAttribute('aria-label').catch(() => ''))
    || '';
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
  return (await page.locator('body').innerText().catch(() => '')).trim().slice(0, 4000);
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
    headless: false,
    timeoutMs: 600000,
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
        options.headless = (next ?? 'false').toLowerCase() === 'true';
        index += 1;
        break;
      case '--timeout-ms':
        options.timeoutMs = Number.parseInt(next ?? '600000', 10);
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
  if (!options.statePath) missing.push('statePath');
  if (!options.outputDir) missing.push('outputDir');

  if (missing.length) {
    throw new Error(`Configurazione accesso MioDottore incompleta: mancano ${missing.join(', ')}.`);
  }
}

function log(message, stream = 'stdout') {
  const prefix = '[miodottore-login]';
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

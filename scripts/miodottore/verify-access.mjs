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

const LOGIN_PATH_REGEX = /(login|accedi|sign-?in|log-?in|entra)/i;

async function main() {
  const options = parseArgs(process.argv.slice(2));
  validateOptions(options);

  await fs.mkdir(options.outputDir, { recursive: true });

  const browser = await chromium.launch({
    headless: options.headless,
    slowMo: 0,
    executablePath: options.chromiumPath || undefined,
  });

  const context = await browser.newContext({
    viewport: { width: 1440, height: 960 },
    storageState: options.statePath,
  });
  const page = await context.newPage();
  const providerObserver = createProviderApiObserver(page);

  let result = {
    success: false,
    status: 'session_expired',
    message: 'Sessione MioDottore non valida o scaduta.',
    final_url: '',
    page_title: '',
    host: '',
    is_internal_app: false,
    is_login_page: false,
    is_app_selection_page: false,
    has_login_form: false,
    public_homepage_visible: false,
    provider_api_response: null,
    api_results: [],
  };

  try {
    await page.goto(options.verifyUrl || PROVIDER_APP_URL, {
      waitUntil: 'domcontentloaded',
      timeout: options.timeoutMs,
    });
    await page.waitForTimeout(1200);

    const currentUrl = page.url();
    const title = await safePageTitle(page);
    const hasLoginForm = await page.locator('input[type="password"]').first().isVisible().catch(() => false);
    const preAnalysis = analyzeAccessState(currentUrl, options.loginUrl, hasLoginForm);

    if (preAnalysis.status === 'app_selection_required') {
      await writeSnapshot(page, options.outputDir, snapshotNameForStatus(preAnalysis.status));
      result = {
        success: false,
        status: preAnalysis.status,
        message: messageForStatus(preAnalysis.status),
        final_url: currentUrl,
        page_title: title,
        host: preAnalysis.host,
        is_internal_app: preAnalysis.isInternalApp,
        is_login_page: preAnalysis.isLoginPage,
        is_app_selection_page: preAnalysis.isAppSelectionPage,
        has_login_form: hasLoginForm,
        public_homepage_visible: false,
        provider_api_response: null,
        api_results: [],
      };
      process.exitCode = 1;
      return;
    }

    const providerAccess = await verifyProviderAccess(page, providerObserver, {
      providerAppUrl: options.verifyUrl || PROVIDER_APP_URL,
      timeoutMs: options.timeoutMs,
    });
    const finalUrl = page.url();
    const finalTitle = await safePageTitle(page);
    const finalHasLoginForm = await page.locator('input[type="password"]').first().isVisible().catch(() => false);
    const finalAnalysis = analyzeAccessState(finalUrl, options.loginUrl, finalHasLoginForm);

    await writeSnapshot(page, options.outputDir, snapshotNameForStatus(providerAccess.success ? 'session_valid' : 'session_expired'));

    result = {
      success: providerAccess.success,
      status: providerAccess.success ? 'session_valid' : 'session_expired',
      message: providerAccess.success ? 'Accesso MioDottore verificato correttamente.' : providerAccess.message,
      final_url: finalUrl,
      page_title: finalTitle,
      host: providerAccess.host,
      is_internal_app: providerAccess.internal_app_visible,
      is_login_page: finalAnalysis.isLoginPage,
      is_app_selection_page: finalAnalysis.isAppSelectionPage,
      has_login_form: finalHasLoginForm,
      public_homepage_visible: providerAccess.public_homepage_visible,
      provider_api_response: providerAccess.provider_api_response,
      api_results: providerAccess.api_results,
    };

    if (!providerAccess.success) {
      process.exitCode = 1;
    }
  } catch (error) {
    result = {
      success: false,
      status: 'error',
      message: error instanceof Error ? error.message : 'Verifica accesso MioDottore fallita.',
      final_url: page.url(),
      page_title: await safePageTitle(page),
      host: safeUrlHost(page.url()),
      is_internal_app: false,
      is_login_page: false,
      is_app_selection_page: false,
      has_login_form: false,
      public_homepage_visible: false,
      provider_api_response: null,
      api_results: [],
    };
    await writeSnapshot(page, options.outputDir, 'verify-error').catch(() => undefined);
    process.exitCode = 1;
  } finally {
    await fs.writeFile(path.join(options.outputDir, 'result.json'), JSON.stringify(result, null, 2), 'utf8');
    await browser.close().catch(() => undefined);
  }
}

function analyzeAccessState(currentUrl, loginUrl, hasLoginForm) {
  const current = safeParseUrl(currentUrl);
  const login = safeParseUrl(loginUrl);
  const host = current?.host ?? '';
  const pathname = current?.pathname?.toLowerCase() ?? '';
  const loginHost = login?.host ?? '';
  const loginPathname = login?.pathname?.toLowerCase() ?? '';

  const isAppSelectionPage = host === 'l.miodottore.it' && pathname.startsWith('/apps');
  const isDocplannerHost = host === 'docplanner.miodottore.it';
  const isInternalApp = isDocplannerHost;
  const sameLoginLocation = normalizeComparableUrl(currentUrl) === normalizeComparableUrl(loginUrl);
  const looksLikeLoginPath = LOGIN_PATH_REGEX.test(pathname) || pathname === loginPathname || pathname === '/' || pathname === '';
  const isLoginPage = hasLoginForm || sameLoginLocation || (host === loginHost && looksLikeLoginPath && !isAppSelectionPage);

  if (isInternalApp && !isLoginPage && !isAppSelectionPage && !hasLoginForm) {
    return {
      success: true,
      status: 'session_valid',
      host,
      isInternalApp,
      isLoginPage,
      isAppSelectionPage,
    };
  }

  if (isAppSelectionPage && !hasLoginForm) {
    return {
      success: false,
      status: 'app_selection_required',
      host,
      isInternalApp: false,
      isLoginPage: false,
      isAppSelectionPage,
    };
  }

  if (isLoginPage || hasLoginForm) {
    return {
      success: false,
      status: 'session_expired',
      host,
      isInternalApp,
      isLoginPage: true,
      isAppSelectionPage,
    };
  }

  return {
    success: false,
    status: 'session_expired',
    host,
    isInternalApp,
    isLoginPage,
    isAppSelectionPage,
  };
}

function messageForStatus(status) {
  switch (status) {
    case 'session_valid':
      return 'Accesso MioDottore verificato correttamente.';
    case 'app_selection_required':
      return 'Accesso eseguito, completa la selezione dell applicazione MioDottore.';
    case 'session_expired':
      return PUBLIC_PORTAL_ACCESS_ERROR;
    default:
      return 'Verifica accesso MioDottore fallita.';
  }
}

function snapshotNameForStatus(status) {
  switch (status) {
    case 'session_valid':
      return 'verify-success';
    case 'app_selection_required':
      return 'verify-app-selection';
    case 'session_expired':
      return 'verify-failed';
    default:
      return 'verify-error';
  }
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
  return (await page.locator('body').innerText().catch(() => '')).trim().slice(0, 8000);
}

async function safePageTitle(page) {
  return page.title().catch(() => '');
}

function safeUrlHost(value) {
  return safeParseUrl(value)?.host ?? '';
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

  if (missing.length) {
    throw new Error(`Configurazione verifica MioDottore incompleta: mancano ${missing.join(', ')}.`);
  }
}

main().catch((error) => {
  console.error('[miodottore-verify] ' + (error instanceof Error ? error.stack ?? error.message : String(error)));
  process.exit(1);
});

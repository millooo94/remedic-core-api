const PROVIDER_APP_URL = 'https://docplanner.miodottore.it/#/';
const PROVIDER_API_PATHS = [
  '/api/auth/provider',
  '/api/profile',
  '/api/schedules',
  '/api/calendarevents',
];
const PUBLIC_PORTAL_ACCESS_ERROR = 'Accesso effettuato sul portale pubblico MioDottore, ma non valido per l area gestionale provider. Accedi con l account amministrativo/provider corretto.';

function safeParseUrl(value) {
  try {
    return new URL(value);
  } catch {
    return null;
  }
}

function normalizeComparableUrl(value) {
  const parsed = safeParseUrl(value);
  if (!parsed) {
    return String(value || '').trim().replace(/\/+$/, '');
  }

  parsed.hash = '';

  return parsed.toString().replace(/\/+$/, '');
}

function summarizeProviderApiResults(results) {
  const operationalApiPaths = new Set(['/api/profile', '/api/schedules', '/api/calendarevents']);
  const successfulResponse = results.find((item) => (
    item.ok
    && item.status === 200
    && operationalApiPaths.has(item.api_path)
  )) ?? null;
  const authFailureResponse = results.find((item) => (
    (item.status === 401 || item.status === 403)
    && operationalApiPaths.has(item.api_path)
  )) ?? null;
  const ssoOnlyResponse = results.find((item) => item.ok && item.status === 200 && item.api_path === '/api/auth/provider') ?? null;

  return {
    successfulResponse,
    authFailureResponse,
    ssoOnlyResponse,
    hasSuccess: successfulResponse !== null,
    hasAuthFailure: authFailureResponse !== null,
    hasSsoOnlySuccess: ssoOnlyResponse !== null,
  };
}

function createProviderApiObserver(page) {
  const responses = [];

  page.on('response', async (response) => {
    try {
      const parsed = safeParseUrl(response.url());
      const host = parsed?.host ?? '';
      const pathname = parsed?.pathname ?? '';
      if (host !== 'docplanner.miodottore.it') {
        return;
      }

      if (!PROVIDER_API_PATHS.some((apiPath) => pathname.startsWith(apiPath))) {
        return;
      }

      responses.push({
        url: response.url(),
        status: response.status(),
        ok: response.ok(),
        api_path: pathname,
        captured_at: new Date().toISOString(),
      });
    } catch {
      // Ignore observer errors: explicit probing below remains the source of truth.
    }
  });

  return {
    getResponses() {
      return [...responses];
    },
  };
}

async function probeProviderApis(page) {
  let lastError = null;

  for (let attempt = 1; attempt <= 4; attempt += 1) {
    try {
      return await page.evaluate(async (apiPaths) => {
        const results = [];

        for (const apiPath of apiPaths) {
          try {
            const response = await fetch(apiPath, {
              method: 'GET',
              credentials: 'include',
              headers: {
                accept: 'application/json, text/plain, */*',
              },
            });

            results.push({
              api_path: apiPath,
              url: response.url || `${window.location.origin}${apiPath}`,
              status: response.status,
              ok: response.ok,
            });

            if (response.ok) {
              break;
            }
          } catch (error) {
            results.push({
              api_path: apiPath,
              error: error instanceof Error ? error.message : String(error),
            });
          }
        }

        return results;
      }, PROVIDER_API_PATHS);
    } catch (error) {
      lastError = error;
      const message = error instanceof Error ? error.message : String(error);

      if (!/Execution context was destroyed|navigation|Target page|frame was detached/i.test(message)) {
        return [{ api_path: 'provider_probe', error: message }];
      }

      await page.waitForLoadState('domcontentloaded', { timeout: 8000 }).catch(() => undefined);
      await page.waitForTimeout(1200);
    }
  }

  return [{
    api_path: 'provider_probe',
    error: lastError instanceof Error ? lastError.message : String(lastError || 'Provider API probe failed'),
  }];
}

async function verifyProviderAccess(page, observer, {
  providerAppUrl = PROVIDER_APP_URL,
  timeoutMs = 45000,
  settleMs = 3500,
  pollIntervalMs = 2500,
} = {}) {
  const parsedCurrentUrl = safeParseUrl(page.url());
  if (parsedCurrentUrl?.host !== 'docplanner.miodottore.it') {
    await page.goto(providerAppUrl, {
      waitUntil: 'domcontentloaded',
      timeout: Math.min(timeoutMs, 30000),
    });
  }

  await page.waitForTimeout(settleMs);

  const startedAt = Date.now();
  let lastSnapshot = null;

  while (Date.now() - startedAt < timeoutMs) {
    await page.waitForLoadState('domcontentloaded', { timeout: 8000 }).catch(() => undefined);

    const probeResults = await probeProviderApis(page);
    const observedResults = observer?.getResponses?.() ?? [];
    const combinedResults = dedupeApiResults([...observedResults, ...probeResults]);
    const summary = summarizeProviderApiResults(combinedResults);
    const finalUrl = page.url();
    const host = safeParseUrl(finalUrl)?.host ?? '';
    const publicHomepageVisible = host === 'www.miodottore.it' || host === 'miodottore.it';
    const internalAppVisible = host === 'docplanner.miodottore.it';
    const providerHostVisible = host === 'docplanner.miodottore.it';

    lastSnapshot = {
      final_url: finalUrl,
      host,
      internal_app_visible: internalAppVisible,
      public_homepage_visible: publicHomepageVisible,
      api_results: combinedResults,
      summary,
    };

    if (!providerHostVisible || !internalAppVisible || publicHomepageVisible) {
      return {
        success: false,
        final_url: finalUrl,
        host,
        internal_app_visible: internalAppVisible,
        public_homepage_visible: publicHomepageVisible,
        api_results: combinedResults,
        provider_api_response: summary.authFailureResponse,
        message: PUBLIC_PORTAL_ACCESS_ERROR,
        status: 'error',
      };
    }

    if (summary.hasSuccess) {
      return {
        success: true,
        final_url: finalUrl,
        host,
        internal_app_visible: internalAppVisible,
        public_homepage_visible: publicHomepageVisible,
        api_results: combinedResults,
        provider_api_response: summary.successfulResponse,
        message: 'Accesso MioDottore provider verificato correttamente.',
        status: 'session_valid',
      };
    }

    await page.waitForTimeout(pollIntervalMs);
  }

  if (lastSnapshot?.summary?.hasAuthFailure) {
    return {
      success: false,
      final_url: lastSnapshot.final_url,
      host: lastSnapshot.host,
      internal_app_visible: lastSnapshot.internal_app_visible,
      public_homepage_visible: lastSnapshot.public_homepage_visible,
      api_results: lastSnapshot.api_results,
      provider_api_response: lastSnapshot.summary.authFailureResponse,
      message: PUBLIC_PORTAL_ACCESS_ERROR,
      status: 'error',
    };
  }

  if (lastSnapshot?.summary?.hasSsoOnlySuccess) {
    return {
      success: false,
      final_url: lastSnapshot.final_url,
      host: lastSnapshot.host,
      internal_app_visible: lastSnapshot.internal_app_visible,
      public_homepage_visible: lastSnapshot.public_homepage_visible,
      api_results: lastSnapshot.api_results,
      provider_api_response: lastSnapshot.summary.ssoOnlyResponse,
      message: 'La sessione MioDottore non risulta valida per l area gestionale provider. /api/auth/provider risponde, ma /api/profile, /api/schedules o /api/calendarevents non risultano autorizzati.',
      status: 'error',
    };
  }

  return {
    success: false,
    final_url: lastSnapshot?.final_url ?? page.url(),
    host: lastSnapshot?.host ?? (safeParseUrl(page.url())?.host ?? ''),
    internal_app_visible: lastSnapshot?.internal_app_visible ?? false,
    public_homepage_visible: lastSnapshot?.public_homepage_visible ?? false,
    api_results: lastSnapshot?.api_results ?? [],
    provider_api_response: lastSnapshot?.summary?.authFailureResponse ?? null,
    message: 'Sessione MioDottore non valida per l area gestionale provider.',
    status: 'error',
  };
}

function dedupeApiResults(items) {
  const seen = new Set();

  return items.filter((item) => {
    const key = `${item.url ?? item.api_path ?? 'unknown'}|${item.status ?? item.error ?? 'na'}`;
    if (seen.has(key)) {
      return false;
    }

    seen.add(key);
    return true;
  });
}

export {
  PROVIDER_API_PATHS,
  PROVIDER_APP_URL,
  PUBLIC_PORTAL_ACCESS_ERROR,
  createProviderApiObserver,
  normalizeComparableUrl,
  safeParseUrl,
  summarizeProviderApiResults,
  verifyProviderAccess,
};

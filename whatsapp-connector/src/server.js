'use strict';

const fs = require('fs');
const fsp = require('fs/promises');
const path = require('path');
const { execFile } = require('child_process');
const { promisify } = require('util');

const dotenv = require('dotenv');
const express = require('express');
const QRCode = require('qrcode');
const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');
const execFileAsync = promisify(execFile);

dotenv.config({ path: path.resolve(__dirname, '../../.env') });
dotenv.config();

const CONNECTOR_HOST = process.env.WHATSAPP_PUPPETEER_HOST || '127.0.0.1';
const CONNECTOR_PORT = Number(process.env.WHATSAPP_PUPPETEER_PORT || 3101);
const CONNECTOR_TOKEN = String(process.env.WHATSAPP_PUPPETEER_TOKEN || '').trim();
const DEFAULT_SESSION_PATH = path.resolve(__dirname, '../../storage/app/whatsapp-web-session');
const SESSION_PATH = resolveSessionPath(
  process.env.WHATSAPP_PUPPETEER_SESSION_PATH,
  DEFAULT_SESSION_PATH
);
const CLIENT_ID = String(process.env.WHATSAPP_PUPPETEER_CLIENT_ID || 'remedic-marketing');
const CHROMIUM_PATH = String(process.env.WHATSAPP_PUPPETEER_CHROMIUM_PATH || '').trim();
const HEADLESS = toBoolean(process.env.WHATSAPP_PUPPETEER_HEADLESS, true);
const DISABLE_SANDBOX = toBoolean(process.env.WHATSAPP_PUPPETEER_DISABLE_SANDBOX, true);
const LAUNCH_TIMEOUT_MS = Number(process.env.WHATSAPP_PUPPETEER_LAUNCH_TIMEOUT_MS || 120000);
const MESSAGE_TIMEOUT_MS = Number(process.env.WHATSAPP_PUPPETEER_MESSAGE_TIMEOUT_MS || 45000);
const SEND_DELAY_MS = Number(process.env.WHATSAPP_PUPPETEER_SEND_DELAY_MS || 900);
const MAX_SEND_RETRIES = Number(process.env.WHATSAPP_PUPPETEER_MAX_SEND_RETRIES || 2);
const CONNECT_WAIT_MS = Number(process.env.WHATSAPP_PUPPETEER_CONNECT_WAIT_MS || 20000);
const CONNECT_POLL_MS = Number(process.env.WHATSAPP_PUPPETEER_CONNECT_POLL_MS || 300);
const SESSION_RESET_DELAY_MS = Number(process.env.WHATSAPP_PUPPETEER_SESSION_RESET_DELAY_MS || 700);
const CHROMIUM_SHUTDOWN_WAIT_MS = Number(process.env.WHATSAPP_PUPPETEER_CHROMIUM_SHUTDOWN_WAIT_MS || 1500);

const app = express();
app.use(express.json({ limit: '10mb' }));

let client = null;
let queueDepth = 0;
let sendQueue = Promise.resolve();
let lastQrPayload = null;
let activeClientGeneration = 0;
let connectorOperationQueue = Promise.resolve();
let currentInitPromise = null;
let currentOperation = null;

const SESSION_PROFILE_PATH = path.join(SESSION_PATH, `session-${CLIENT_ID}`);
const STALE_BROWSER_MARKERS = Array.from(new Set([
  SESSION_PATH,
  SESSION_PROFILE_PATH,
  `session-${CLIENT_ID}`,
  CLIENT_ID,
  path.basename(SESSION_PATH),
  'puppeteer_dev_chrome_profile',
])).filter(Boolean);

const status = {
  state: 'disconnected',
  ready: false,
  message: 'WhatsApp non collegato. Clicca Collega WhatsApp per generare un nuovo QR code.',
  qrRequired: false,
  qrCodeDataUrl: null,
  qrUpdatedAt: null,
  webState: null,
  queueDepth: 0,
  phoneNumber: null,
  pushName: null,
  lastErrorCode: null,
  lastErrorMessage: null,
  lastEventAt: isoNow(),
  lastConnectedAt: null,
  processId: process.pid,
  sessionPath: SESSION_PATH,
  clientGeneration: 0,
};

function toBoolean(value, fallback) {
  if (value === undefined || value === null || value === '') {
    return fallback;
  }

  const normalized = String(value).trim().toLowerCase();
  return ['1', 'true', 'yes', 'on'].includes(normalized);
}

function isoNow() {
  return new Date().toISOString();
}

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function resolveSessionPath(configuredPath, fallbackPath) {
  if (!configuredPath || String(configuredPath).trim() === '') {
    return fallbackPath;
  }

  const resolvedPath = path.resolve(String(configuredPath).trim());
  const parentDirectory = path.dirname(resolvedPath);

  if (fs.existsSync(resolvedPath) || fs.existsSync(parentDirectory)) {
    return resolvedPath;
  }

  return fallbackPath;
}

function updateStatus(next = {}) {
  Object.assign(status, next, {
    queueDepth,
    lastEventAt: isoNow(),
    processId: process.pid,
    sessionPath: SESSION_PATH,
    clientGeneration: activeClientGeneration,
  });
}

function setOperation(name) {
  currentOperation = name;
}

function connectorResponse(extra = {}) {
  return {
    state: status.state,
    ready: status.ready,
    message: status.message,
    qr_required: status.qrRequired,
    qr_code_data_url: status.qrCodeDataUrl,
    qr_updated_at: status.qrUpdatedAt,
    web_state: status.webState,
    queue_depth: status.queueDepth,
    phone_number: status.phoneNumber,
    push_name: status.pushName,
    last_error_code: status.lastErrorCode,
    last_error_message: status.lastErrorMessage,
    last_event_at: status.lastEventAt,
    last_connected_at: status.lastConnectedAt,
    process_id: status.processId,
    session_path: status.sessionPath,
    client_generation: status.clientGeneration,
    ...extra,
  };
}

function logConnector(event, details = {}) {
  const payload = {
    pid: process.pid,
    generation: activeClientGeneration,
    state: status.state,
    sessionPath: SESSION_PATH,
    ...details,
  };

  console.log(`[WhatsApp] ${event}`, payload);
}

function classifyError(error) {
  const message = String(error && error.message ? error.message : error || 'Errore tecnico sconosciuto');
  const lowered = message.toLowerCase();

  if (
    lowered.includes('already running for') ||
    lowered.includes('userdatadir') ||
    lowered.includes('stop the running browser first')
  ) {
    return {
      providerStatus: 'browser_locked',
      connectorState: 'browser_locked',
      friendlyMessage: 'Browser precedente rimasto attivo sulla sessione WhatsApp. Esegui un reset sessione o attendi qualche secondo e riprova.',
    };
  }

  if (
    lowered.includes('eacces') ||
    lowered.includes('eperm') ||
    lowered.includes('permission denied') ||
    lowered.includes('session cleanup failed') ||
    lowered.includes('impossibile pulire la sessione')
  ) {
    return {
      providerStatus: 'session_cleanup_failed',
      connectorState: 'session_cleanup_failed',
      friendlyMessage: 'Pulizia sessione WhatsApp fallita. Verificare permessi o processi Chromium residui.',
    };
  }

  if (
    lowered.includes('failed to launch') ||
    lowered.includes('spawn') ||
    lowered.includes('enoent') ||
    lowered.includes('browser') ||
    lowered.includes('chrome')
  ) {
    return {
      providerStatus: 'browser_unavailable',
      connectorState: 'browser_unavailable',
      friendlyMessage: 'Browser Chromium/Puppeteer non avviabile sul server. Verificare installazione e percorso eseguibile.',
    };
  }

  if (
    lowered.includes('selector') ||
    lowered.includes('execution context') ||
    lowered.includes('evaluation failed') ||
    lowered.includes('protocol error') ||
    lowered.includes('cannot find context') ||
    lowered.includes('waiting for selector')
  ) {
    return {
      providerStatus: 'ui_incompatible',
      connectorState: 'ui_incompatible',
      friendlyMessage: 'Automazione WhatsApp momentaneamente non compatibile con l\'interfaccia corrente. Verificare il connettore WhatsApp.',
    };
  }

  if (
    lowered.includes('not authenticated') ||
    lowered.includes('session closed') ||
    lowered.includes('session not ready') ||
    lowered.includes('qr required') ||
    lowered.includes('auth failure')
  ) {
    return {
      providerStatus: status.qrRequired ? 'qr_required' : 'session_not_ready',
      connectorState: status.qrRequired ? 'qr_required' : 'session_expired',
      friendlyMessage: status.qrRequired
        ? 'WhatsApp non collegato: apri il QR code e completa l\'accesso su WhatsApp Web.'
        : 'Sessione WhatsApp non pronta o scaduta. Ricollega WhatsApp Web prima di inviare.',
    };
  }

  if (lowered.includes('timeout')) {
    return {
      providerStatus: 'timeout',
      connectorState: status.state,
      friendlyMessage: 'Invio WhatsApp non completato entro il tempo previsto.',
    };
  }

  return {
    providerStatus: 'technical_error',
    connectorState: 'technical_error',
    friendlyMessage: 'Errore tecnico durante l\'invio WhatsApp. Riprovare o verificare il connettore.',
  };
}

async function withTimeout(promise, timeoutMs, timeoutMessage) {
  let timeoutId = null;

  const timeoutPromise = new Promise((_, reject) => {
    timeoutId = setTimeout(() => reject(new Error(timeoutMessage)), timeoutMs);
  });

  try {
    return await Promise.race([promise, timeoutPromise]);
  } finally {
    if (timeoutId) {
      clearTimeout(timeoutId);
    }
  }
}

function normalizeTarget(target) {
  return String(target || '').replace(/\D+/g, '');
}

function isConnectorReady() {
  return !!client && status.ready && status.state === 'connected';
}

function isConnectionStateActive() {
  return currentOperation === 'connect'
    || currentOperation === 'reconnect'
    || currentOperation === 'reset-session'
    || currentOperation === 'disconnect'
    || currentInitPromise !== null
    || [
      'starting',
      'connecting',
      'authenticated',
      'waiting_for_scan',
      'qr_required',
      'connected',
    ].includes(status.state);
}

async function ensureSessionDirectory() {
  await fsp.mkdir(SESSION_PATH, { recursive: true });
}

async function assertSessionDirectoryWritable() {
  await ensureSessionDirectory();

  const probePath = path.join(SESSION_PATH, `.write-test-${process.pid}-${Date.now()}`);
  await fsp.writeFile(probePath, 'ok', 'utf8');
  await fsp.rm(probePath, { force: true });
}

async function hasPersistedSessionData() {
  try {
    if (!fs.existsSync(SESSION_PATH)) {
      return false;
    }

    const entries = await fsp.readdir(SESSION_PATH, { withFileTypes: true });

    for (const entry of entries) {
      if (entry.name.startsWith('.')) {
        continue;
      }

      if (entry.isFile()) {
        return true;
      }

      if (entry.isDirectory()) {
        const nestedEntries = await fsp.readdir(path.join(SESSION_PATH, entry.name));
        if (nestedEntries.length > 0) {
          return true;
        }
      }
    }

    return false;
  } catch (_) {
    return false;
  }
}

function setDisconnectedStatus(message = 'WhatsApp non collegato. Clicca Collega WhatsApp per generare un nuovo QR code.') {
  lastQrPayload = null;
  updateStatus({
    state: 'disconnected',
    ready: false,
    qrRequired: false,
    qrCodeDataUrl: null,
    qrUpdatedAt: null,
    message,
    webState: null,
    phoneNumber: null,
    pushName: null,
    lastErrorCode: null,
    lastErrorMessage: null,
  });
}

function runSerializedConnectorOperation(operation) {
  const queuedOperation = connectorOperationQueue.then(operation, operation);
  connectorOperationQueue = queuedOperation.then(() => undefined, () => undefined);

  return queuedOperation;
}

function setCleanupFailedStatus(message, error) {
  lastQrPayload = null;
  updateStatus({
    state: 'session_cleanup_failed',
    ready: false,
    qrRequired: false,
    qrCodeDataUrl: null,
    qrUpdatedAt: null,
    message,
    webState: null,
    phoneNumber: null,
    pushName: null,
    lastErrorCode: 'session_cleanup_failed',
    lastErrorMessage: String(error && error.message ? error.message : error || message),
  });
}

function isActiveClient(targetClient, generation) {
  return client === targetClient && activeClientGeneration === generation;
}

function getTrackedBrowser(targetClient) {
  if (!targetClient) {
    return null;
  }

  return targetClient.pupBrowser || targetClient.browser || null;
}

async function terminateBrowserProcess(browser, reason = 'cleanup') {
  if (!browser || typeof browser.process !== 'function') {
    return;
  }

  const child = browser.process();
  if (!child || !child.pid) {
    return;
  }

  logConnector('terminating browser process', {
    reason,
    pid: child.pid,
  });

  try {
    child.kill('SIGTERM');
  } catch (_) {
    return;
  }

  await wait(CHROMIUM_SHUTDOWN_WAIT_MS);

  if (child.exitCode === null) {
    try {
      child.kill('SIGKILL');
    } catch (_) {
      // Best effort cleanup.
    }
  }
}

async function closeTrackedBrowser(targetClient, reason = 'cleanup') {
  const browser = getTrackedBrowser(targetClient);
  if (!browser) {
    return;
  }

  try {
    if (typeof browser.close === 'function') {
      await browser.close();
    }
  } catch (error) {
    logConnector('browser close failed', {
      reason,
      message: String(error && error.message ? error.message : error),
    });
  }

  await terminateBrowserProcess(browser, reason);
}

async function safeDestroyClient(reason = 'cleanup', targetClient = client) {
  if (!targetClient) {
    return;
  }

  if (targetClient === client) {
    client = null;
    currentInitPromise = null;
  }

  const previousGeneration = activeClientGeneration;
  logConnector('destroy client requested', {
    reason,
    generation: previousGeneration,
  });

  try {
    targetClient.removeAllListeners();
  } catch (_) {
    // Best effort cleanup.
  }

  await closeTrackedBrowser(targetClient, reason);

  try {
    await targetClient.destroy();
  } catch (error) {
    logConnector('client destroy failed', {
      reason,
      message: String(error && error.message ? error.message : error),
    });
  }

  await closeTrackedBrowser(targetClient, `${reason}:post-destroy`);
}

async function isPidAlive(pid) {
  try {
    process.kill(pid, 0);
    return true;
  } catch (_) {
    return false;
  }
}

async function cleanupStaleChromiumProcesses() {
  if (process.platform === 'win32') {
    return [];
  }

  let stdout = '';
  try {
    const result = await execFileAsync('ps', ['-eo', 'pid=,command=']);
    stdout = String(result.stdout || '');
  } catch (error) {
    logConnector('ps scan failed', {
      message: String(error && error.message ? error.message : error),
    });
    return [];
  }

  const staleProcesses = stdout
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => {
      const match = line.match(/^(\d+)\s+(.*)$/);
      if (!match) {
        return null;
      }

      return {
        pid: Number(match[1]),
        command: match[2],
      };
    })
    .filter((entry) => entry && entry.pid > 0)
    .filter((entry) => /chrom(e|ium)|chrome-headless-shell/i.test(entry.command))
    .filter((entry) => STALE_BROWSER_MARKERS.some((marker) => entry.command.includes(marker)));

  if (!staleProcesses.length) {
    return [];
  }

  logConnector('stale chromium processes detected', {
    processes: staleProcesses,
  });

  for (const processInfo of staleProcesses) {
    try {
      process.kill(processInfo.pid, 'SIGTERM');
    } catch (_) {
      // Best effort cleanup.
    }
  }

  await wait(CHROMIUM_SHUTDOWN_WAIT_MS);

  for (const processInfo of staleProcesses) {
    if (await isPidAlive(processInfo.pid)) {
      try {
        process.kill(processInfo.pid, 'SIGKILL');
      } catch (_) {
        // Best effort cleanup.
      }
    }
  }

  return staleProcesses;
}

async function safeResetSessionDirectory(reason = 'manual_reset', options = {}) {
  const skipDestroy = options.skipDestroy === true;

  if (!skipDestroy) {
    await safeDestroyClient(reason);
  }

  await cleanupStaleChromiumProcesses();
  await wait(SESSION_RESET_DELAY_MS);

  try {
    await fsp.rm(SESSION_PATH, { recursive: true, force: true });
    await ensureSessionDirectory();
    await assertSessionDirectoryWritable();
  } catch (error) {
    logConnector('session cleanup failed', {
      reason,
      message: String(error && error.message ? error.message : error),
    });
    setCleanupFailedStatus('Impossibile pulire la sessione WhatsApp. Verificare permessi o processi Chromium residui.', error);
    throw error;
  }
}

async function ensureConnectorEnvironment() {
  await assertSessionDirectoryWritable();

  if (CHROMIUM_PATH && !fs.existsSync(CHROMIUM_PATH)) {
    throw new Error(`Chromium executable not found at ${CHROMIUM_PATH}`);
  }
}

async function initializeClient(options = {}) {
  const resetSession = Boolean(options.resetSession);

  if (!resetSession && client) {
    return {
      client,
      generation: activeClientGeneration,
    };
  }

  if (!resetSession && currentInitPromise) {
    return {
      client,
      generation: activeClientGeneration,
    };
  }

  if (resetSession) {
    await safeResetSessionDirectory('connect_reset');
  }

  await ensureConnectorEnvironment();

  activeClientGeneration += 1;
  const generation = activeClientGeneration;

  updateStatus({
    state: 'starting',
    ready: false,
    qrRequired: false,
    qrCodeDataUrl: null,
    qrUpdatedAt: null,
    message: 'Avvio del connettore WhatsApp in corso.',
    lastErrorCode: null,
    lastErrorMessage: null,
  });
  logConnector('client initializing', {
    resetSession,
    generation,
  });

  const puppeteerArgs = [
    '--disable-dev-shm-usage',
    '--disable-gpu',
    '--no-first-run',
    '--no-default-browser-check',
  ];

  if (DISABLE_SANDBOX) {
    puppeteerArgs.push('--no-sandbox', '--disable-setuid-sandbox');
  }

  const nextClient = new Client({
    authStrategy: new LocalAuth({
      clientId: CLIENT_ID,
      dataPath: SESSION_PATH,
    }),
    puppeteer: {
      headless: HEADLESS,
      executablePath: CHROMIUM_PATH || undefined,
      args: puppeteerArgs,
      timeout: LAUNCH_TIMEOUT_MS,
    },
  });

  const handleStaleEvent = (event, details = {}) => {
    if (isActiveClient(nextClient, generation)) {
      return false;
    }

    logConnector(`${event} ignored for stale client`, {
      generation,
      ...details,
    });

    return true;
  };

  nextClient.on('qr', async (qr) => {
    if (handleStaleEvent('qr')) {
      return;
    }

    lastQrPayload = qr;
    const qrCodeDataUrl = await QRCode.toDataURL(qr, { margin: 1, width: 280 });
    updateStatus({
      state: 'qr_required',
      ready: false,
      qrRequired: true,
      qrCodeDataUrl,
      qrUpdatedAt: isoNow(),
      message: 'QR richiesto: collega WhatsApp Web per abilitare il canale.',
      lastErrorCode: null,
      lastErrorMessage: null,
    });
    logConnector('qr received', {
      generation,
      qrUpdatedAt: status.qrUpdatedAt,
    });
  });

  nextClient.on('authenticated', () => {
    if (handleStaleEvent('authenticated')) {
      return;
    }

    updateStatus({
      state: 'connecting',
      ready: false,
      qrRequired: false,
      qrCodeDataUrl: null,
      qrUpdatedAt: null,
      message: 'Sessione autenticata, sincronizzazione WhatsApp in corso.',
    });
    logConnector('authenticated', {
      generation,
    });
  });

  nextClient.on('ready', () => {
    if (handleStaleEvent('ready')) {
      return;
    }

    const info = nextClient.info || null;
    lastQrPayload = null;
    updateStatus({
      state: 'connected',
      ready: true,
      qrRequired: false,
      qrCodeDataUrl: null,
      qrUpdatedAt: null,
      message: 'WhatsApp Web collegato e pronto all\'invio.',
      phoneNumber: info?.wid?.user || null,
      pushName: info?.pushname || null,
      lastConnectedAt: isoNow(),
      lastErrorCode: null,
      lastErrorMessage: null,
    });
    logConnector('ready', {
      generation,
      phoneNumber: info?.wid?.user || null,
      pushName: info?.pushname || null,
    });
  });

  nextClient.on('change_state', (webState) => {
    if (handleStaleEvent('change_state', { webState })) {
      return;
    }

    updateStatus({
      webState,
    });
    logConnector('change_state', {
      generation,
      webState,
    });
  });

  nextClient.on('auth_failure', (message) => {
    if (handleStaleEvent('auth_failure', { message: String(message || '') })) {
      return;
    }

    updateStatus({
      state: 'session_expired',
      ready: false,
      qrRequired: false,
      qrCodeDataUrl: null,
      qrUpdatedAt: null,
      message: 'Sessione WhatsApp scaduta o non piu valida. Ricollega l\'account.',
      lastErrorCode: 'auth_failure',
      lastErrorMessage: String(message || 'Autenticazione WhatsApp fallita.'),
    });
    client = null;
    currentInitPromise = null;
    logConnector('auth_failure', {
      generation,
      message: String(message || ''),
    });
    safeDestroyClient('auth_failure', nextClient).catch(() => undefined);
  });

  nextClient.on('disconnected', async (reason) => {
    if (handleStaleEvent('disconnected', { reason: String(reason || '') })) {
      return;
    }

    const normalizedReason = String(reason || '');
    const isLogout = normalizedReason.toUpperCase().includes('LOGOUT');
    logConnector('disconnected', {
      generation,
      reason: normalizedReason,
    });

    try {
      await runSerializedConnectorOperation(async () => {
        await safeDestroyClient('disconnected_event', nextClient);

        if (isLogout) {
          await safeResetSessionDirectory('logout_cleanup', { skipDestroy: true });
          setDisconnectedStatus('WhatsApp disconnesso o sloggato. Genera un nuovo QR code per collegare di nuovo il dispositivo.');
          return;
        }

        setDisconnectedStatus('Connessione WhatsApp interrotta. Clicca Collega WhatsApp per generare un nuovo QR code.');
      });
    } catch (error) {
      setCleanupFailedStatus(
        'Connessione WhatsApp interrotta, ma la pulizia della sessione non e stata completata.',
        error
      );
    }
  });

  nextClient.on('loading_screen', (_percent, message) => {
    if (handleStaleEvent('loading_screen', { message: String(message || '') })) {
      return;
    }

    updateStatus({
      state: 'waiting_for_scan',
      ready: false,
      message: message ? `Preparazione WhatsApp Web: ${message}` : 'Preparazione WhatsApp Web in corso.',
    });
    logConnector('loading_screen', {
      generation,
      message: String(message || ''),
    });
  });

  client = nextClient;

  const initPromise = (async () => {
    try {
      await nextClient.initialize();
    } catch (error) {
      const classification = classifyError(error);

      if (classification.connectorState === 'browser_locked') {
        await cleanupStaleChromiumProcesses();
      }

      if (isActiveClient(nextClient, generation)) {
        updateStatus({
          state: classification.connectorState,
          ready: false,
          qrRequired: false,
          qrCodeDataUrl: null,
          qrUpdatedAt: null,
          message: classification.friendlyMessage,
          lastErrorCode: classification.providerStatus,
          lastErrorMessage: String(error?.message || error),
        });
        client = null;
        currentInitPromise = null;
      }

      await closeTrackedBrowser(nextClient, 'initialize_error');

      logConnector('error', {
        generation,
        message: String(error?.message || error),
      });
    }
  })();

  currentInitPromise = initPromise;
  initPromise.finally(() => {
    if (currentInitPromise === initPromise) {
      currentInitPromise = null;
    }
  }).catch(() => undefined);

  return {
    client: nextClient,
    generation,
  };
}

async function waitForFreshConnectState({ generation, qrNotBeforeMs }) {
  const startedAt = Date.now();

  while ((Date.now() - startedAt) < CONNECT_WAIT_MS) {
    if (generation !== activeClientGeneration) {
      return {
        kind: 'stale_generation',
        message: 'Una nuova istanza WhatsApp ha sostituito il collegamento corrente.',
      };
    }

    if (status.ready && status.state === 'connected') {
      return {
        kind: 'connected',
      };
    }

    if (status.qrRequired && status.qrCodeDataUrl) {
      const qrUpdatedAtMs = status.qrUpdatedAt ? Date.parse(status.qrUpdatedAt) : 0;
      if (!Number.isNaN(qrUpdatedAtMs) && qrUpdatedAtMs >= qrNotBeforeMs) {
        return {
          kind: 'qr_ready',
        };
      }
    }

    if (['browser_unavailable', 'browser_locked', 'session_cleanup_failed', 'ui_incompatible', 'technical_error', 'session_expired', 'error'].includes(status.state)) {
      return {
        kind: 'error',
        message: status.lastErrorMessage || status.message,
      };
    }

    await wait(CONNECT_POLL_MS);
  }

  return {
    kind: 'timeout',
    message: 'Nessun QR ricevuto entro il tempo previsto.',
  };
}

function isImageMimeType(value) {
  return ['image/jpeg', 'image/png', 'image/webp'].includes(String(value || '').toLowerCase());
}

function buildMediaFromPayload({ mediaPath, mediaBase64, mediaMimeType, mediaName }) {
  const normalizedMimeType = String(mediaMimeType || '').trim().toLowerCase();
  if (!isImageMimeType(normalizedMimeType)) {
    return {
      ok: false,
      result: {
        delivery_status: 'failed',
        provider_status: 'media_invalid',
        message_id: null,
        error_message: 'Formato immagine WhatsApp non supportato.',
        response: {
          media_mime_type: mediaMimeType || null,
        },
        sent_at: null,
      },
    };
  }

  const normalizedBase64 = String(mediaBase64 || '').trim();
  if (normalizedBase64 !== '') {
    return {
      ok: true,
      media: new MessageMedia(
        normalizedMimeType,
        normalizedBase64,
        String(mediaName || '').trim() || 'whatsapp-image'
      ),
    };
  }

  const normalizedPath = String(mediaPath || '').trim();
  if (normalizedPath === '') {
    return { ok: true, media: null };
  }

  const resolvedMediaPath = path.resolve(normalizedPath);
  if (!fs.existsSync(resolvedMediaPath)) {
    return {
      ok: false,
      result: {
        delivery_status: 'failed',
        provider_status: 'media_missing',
        message_id: null,
        error_message: 'Immagine WhatsApp non disponibile al momento dell\'invio.',
        response: {
          media_path: resolvedMediaPath,
        },
        sent_at: null,
      },
    };
  }

  return {
    ok: true,
    media: MessageMedia.fromFilePath(resolvedMediaPath),
  };
}

async function performSend({ target, message, mediaPath, mediaBase64, mediaMimeType, mediaName }) {
  if (!isConnectorReady()) {
    return {
      delivery_status: 'failed',
      provider_status: status.qrRequired ? 'qr_required' : 'session_not_ready',
      message_id: null,
      error_message: status.qrRequired
        ? 'WhatsApp non collegato: apri il QR code e completa l\'accesso prima di inviare.'
        : 'Sessione WhatsApp non pronta o scaduta. Verificare il connettore prima di inviare.',
      response: connectorResponse(),
      sent_at: null,
    };
  }

  const normalizedTarget = normalizeTarget(target);
  if (normalizedTarget.length < 8) {
    return {
      delivery_status: 'excluded',
      provider_status: 'invalid_number',
      message_id: null,
      error_message: 'Numero non valido per WhatsApp.',
      response: {
        normalized_target: normalizedTarget,
      },
      sent_at: null,
    };
  }

  const hasMedia = String(mediaPath || '').trim() !== '' || String(mediaBase64 || '').trim() !== '';
  let media = null;

  if (hasMedia) {
    const mediaBuild = buildMediaFromPayload({ mediaPath, mediaBase64, mediaMimeType, mediaName });
    if (!mediaBuild.ok) {
      return mediaBuild.result;
    }

    media = mediaBuild.media;
  }

  for (let attempt = 1; attempt <= MAX_SEND_RETRIES; attempt += 1) {
    try {
      const numberId = await withTimeout(
        client.getNumberId(normalizedTarget),
        MESSAGE_TIMEOUT_MS,
        'Lookup WhatsApp timeout'
      );

      if (!numberId || !numberId._serialized) {
        return {
          delivery_status: 'excluded',
          provider_status: 'no_whatsapp',
          message_id: null,
          error_message: 'Numero non disponibile su WhatsApp.',
          response: {
            normalized_target: normalizedTarget,
          },
          sent_at: null,
        };
      }

      if (SEND_DELAY_MS > 0) {
        await wait(SEND_DELAY_MS);
      }

      let sentMessage = null;

      if (hasMedia && media) {
        sentMessage = await withTimeout(
          client.sendMessage(numberId._serialized, media, {
            caption: message,
          }),
          MESSAGE_TIMEOUT_MS,
          'Send WhatsApp timeout'
        );
      } else {
        sentMessage = await withTimeout(
          client.sendMessage(numberId._serialized, message),
          MESSAGE_TIMEOUT_MS,
          'Send WhatsApp timeout'
        );
      }

      return {
        delivery_status: 'sent',
        provider_status: 'sent',
        message_id: sentMessage?.id?._serialized || null,
        error_message: null,
        response: {
          chat_id: numberId._serialized,
          ack: sentMessage?.ack ?? null,
          from_me: sentMessage?.fromMe ?? true,
          media_sent: hasMedia,
        },
        sent_at: isoNow(),
      };
    } catch (error) {
      const classification = classifyError(error);
      const retryable = classification.providerStatus === 'timeout' || classification.providerStatus === 'technical_error';

      if (retryable && attempt < MAX_SEND_RETRIES) {
        await wait(750);
        continue;
      }

      if (classification.connectorState !== status.state) {
        updateStatus({
          state: classification.connectorState,
          ready: false,
          message: classification.friendlyMessage,
          lastErrorCode: classification.providerStatus,
          lastErrorMessage: String(error?.message || error),
        });
      }

      return {
        delivery_status: 'failed',
        provider_status: classification.providerStatus,
        message_id: null,
        error_message: classification.friendlyMessage,
        response: {
          technical_message: String(error?.message || error),
        },
        sent_at: null,
      };
    }
  }

  return {
    delivery_status: 'failed',
    provider_status: 'technical_error',
    message_id: null,
    error_message: 'Errore tecnico durante l\'invio WhatsApp.',
    response: null,
    sent_at: null,
  };
}

function enqueueSend(payload) {
  queueDepth += 1;
  updateStatus();

  const run = async () => {
    try {
      return await performSend(payload);
    } finally {
      queueDepth = Math.max(0, queueDepth - 1);
      updateStatus();
    }
  };

  const resultPromise = sendQueue.then(run, run);
  sendQueue = resultPromise.then(() => undefined, () => undefined);

  return resultPromise;
}

function authorizeRequest(req, res, next) {
  if (!CONNECTOR_TOKEN) {
    return next();
  }

  const incomingToken = String(req.get('x-connector-token') || '').trim();
  if (incomingToken === CONNECTOR_TOKEN) {
    return next();
  }

  return res.status(401).json({
    message: 'Connector token non valido.',
  });
}

app.get('/status', authorizeRequest, async (_req, res) => {
  res.json(connectorResponse({
    session_path: SESSION_PATH,
    last_qr_available: !!lastQrPayload,
  }));
});

app.post('/connect', authorizeRequest, async (req, res) => {
  const resetSession = req.body?.reset_session ?? true;

  try {
    if (isConnectionStateActive()) {
      return res.status(202).json(connectorResponse({
        requested_reset_session: Boolean(resetSession),
        reused: true,
        message: status.ready
          ? 'WhatsApp e gia collegato.'
          : (status.qrRequired
            ? 'Generazione QR in corso. Scansiona il codice appena disponibile.'
            : 'Collegamento WhatsApp gia in corso. Attendi qualche secondo.'),
      }));
    }

    logConnector('connect requested', {
      resetSession: Boolean(resetSession),
    });
    setOperation('connect');
    const requestStartedAtMs = Date.now();
    const { generation } = await runSerializedConnectorOperation(() => initializeClient({ resetSession: Boolean(resetSession) }));
    const waitResult = await waitForFreshConnectState({
      generation,
      qrNotBeforeMs: requestStartedAtMs,
    });

    res.status(202).json(connectorResponse({
      requested_reset_session: Boolean(resetSession),
      wait_result: waitResult.kind,
      message: waitResult.kind === 'qr_ready'
        ? 'Scansiona il QR code con WhatsApp.'
        : waitResult.kind === 'connected'
          ? 'WhatsApp collegato correttamente.'
          : waitResult.message || 'Generazione di un nuovo QR code WhatsApp avviata.',
    }));
  } catch (error) {
    const classification = classifyError(error);
    res.status(500).json(connectorResponse({
      state: classification.connectorState,
      message: classification.friendlyMessage,
      last_error_code: classification.providerStatus,
      last_error_message: String(error?.message || error),
    }));
  } finally {
    setOperation(null);
  }
});

app.post('/reconnect', authorizeRequest, async (req, res) => {
  const resetSession = Boolean(req.body?.reset_session);

  try {
    if (isConnectionStateActive()) {
      return res.status(202).json(connectorResponse({
        requested_reset_session: resetSession,
        reused: true,
        message: status.qrRequired
          ? 'QR gia in preparazione. Scansiona il codice appena disponibile.'
          : 'Riconnessione WhatsApp gia in corso. Attendi qualche secondo.',
      }));
    }

    logConnector('reconnect requested', {
      resetSession,
    });
    setOperation('reconnect');
    await runSerializedConnectorOperation(() => initializeClient({ resetSession }));
    res.status(202).json(connectorResponse({
      requested_reset_session: resetSession,
      message: resetSession
        ? 'Reset sessione richiesto. Attendere il nuovo QR code.'
        : 'Riconnessione WhatsApp avviata.',
    }));
  } catch (error) {
    const classification = classifyError(error);
    res.status(500).json(connectorResponse({
      state: classification.connectorState,
      message: classification.friendlyMessage,
      last_error_code: classification.providerStatus,
      last_error_message: String(error?.message || error),
    }));
  } finally {
    setOperation(null);
  }
});

app.post('/disconnect', authorizeRequest, async (_req, res) => {
  try {
    logConnector('disconnect requested');
    setOperation('disconnect');
    await runSerializedConnectorOperation(async () => {
      await safeResetSessionDirectory('manual_disconnect');
      setDisconnectedStatus('WhatsApp scollegato. Clicca Collega WhatsApp per generare un nuovo QR code.');
    });

    res.status(202).json(connectorResponse({
      message: 'Sessione WhatsApp scollegata e dati locali puliti.',
    }));
  } catch (error) {
    const classification = classifyError(error);
    res.status(500).json(connectorResponse({
      state: classification.connectorState,
      message: classification.friendlyMessage,
      last_error_code: classification.providerStatus,
      last_error_message: String(error?.message || error),
    }));
  } finally {
    setOperation(null);
  }
});

app.post('/reset-session', authorizeRequest, async (_req, res) => {
  try {
    logConnector('reset session requested');
    setOperation('reset-session');
    await runSerializedConnectorOperation(async () => {
      await safeResetSessionDirectory('manual_reset');
      setDisconnectedStatus('Sessione WhatsApp pulita. Puoi generare un nuovo QR code.');
    });

    res.status(202).json(connectorResponse({
      message: 'Sessione WhatsApp pulita. Puoi generare un nuovo QR code.',
    }));
  } catch (error) {
    const classification = classifyError(error);
    res.status(500).json(connectorResponse({
      state: classification.connectorState,
      message: classification.friendlyMessage,
      last_error_code: classification.providerStatus,
      last_error_message: String(error?.message || error),
    }));
  } finally {
    setOperation(null);
  }
});

app.post('/send', authorizeRequest, async (req, res) => {
  const target = String(req.body?.target || '').trim();
  const message = String(req.body?.message || '').trim();
  const subject = String(req.body?.subject || '').trim();
  const mediaPath = String(req.body?.media_path || '').trim();
  const mediaBase64 = String(req.body?.media_base64 || '').trim();
  const mediaName = String(req.body?.media_name || '').trim();
  const mediaMimeType = String(req.body?.media_mime_type || '').trim();

  if (!target || !message) {
    return res.status(422).json({
      message: 'Target e messaggio sono obbligatori.',
    });
  }

  const result = await enqueueSend({ target, message, subject, mediaPath, mediaBase64, mediaName, mediaMimeType });
  return res.status(result.delivery_status === 'sent' ? 200 : 200).json(result);
});

app.listen(CONNECTOR_PORT, CONNECTOR_HOST, async () => {
  try {
    await ensureConnectorEnvironment();
    if (await hasPersistedSessionData()) {
      runSerializedConnectorOperation(() => initializeClient()).catch(() => undefined);
    } else {
      setDisconnectedStatus();
    }
    console.log(`[whatsapp-connector] listening on http://${CONNECTOR_HOST}:${CONNECTOR_PORT}`);
  } catch (error) {
    const classification = classifyError(error);
    updateStatus({
      state: classification.connectorState,
      ready: false,
      message: classification.friendlyMessage,
      qrUpdatedAt: null,
      lastErrorCode: classification.providerStatus,
      lastErrorMessage: String(error?.message || error),
    });
    console.error('[whatsapp-connector] startup error', error);
  }
});

async function shutdownConnector(signal) {
  logConnector('shutdown requested', { signal });

  try {
    await runSerializedConnectorOperation(async () => {
      await safeDestroyClient(`shutdown:${signal}`);
      await cleanupStaleChromiumProcesses();
      setDisconnectedStatus('WhatsApp non collegato. Il connettore e stato arrestato in modo controllato.');
    });
  } catch (error) {
    logConnector('shutdown cleanup failed', {
      signal,
      message: String(error && error.message ? error.message : error),
    });
  } finally {
    process.exit(0);
  }
}

process.on('SIGINT', () => {
  shutdownConnector('SIGINT').catch(() => process.exit(0));
});

process.on('SIGTERM', () => {
  shutdownConnector('SIGTERM').catch(() => process.exit(0));
});

process.on('unhandledRejection', (error) => {
  logConnector('unhandled rejection', {
    message: String(error && error.message ? error.message : error),
  });
});

process.on('uncaughtException', (error) => {
  logConnector('uncaught exception', {
    message: String(error && error.message ? error.message : error),
  });
});

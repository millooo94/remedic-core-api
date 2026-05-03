'use strict';

const fs = require('fs');
const fsp = require('fs/promises');
const path = require('path');

const dotenv = require('dotenv');
const express = require('express');
const QRCode = require('qrcode');
const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');

dotenv.config({ path: path.resolve(__dirname, '../../.env') });
dotenv.config();

const CONNECTOR_HOST = process.env.WHATSAPP_PUPPETEER_HOST || '127.0.0.1';
const CONNECTOR_PORT = Number(process.env.WHATSAPP_PUPPETEER_PORT || 3101);
const CONNECTOR_TOKEN = String(process.env.WHATSAPP_PUPPETEER_TOKEN || '').trim();
const SESSION_PATH = process.env.WHATSAPP_PUPPETEER_SESSION_PATH
  ? path.resolve(process.env.WHATSAPP_PUPPETEER_SESSION_PATH)
  : path.resolve(__dirname, '../../storage/app/whatsapp-web-session');
const CLIENT_ID = String(process.env.WHATSAPP_PUPPETEER_CLIENT_ID || 'remedic-marketing');
const CHROMIUM_PATH = String(process.env.WHATSAPP_PUPPETEER_CHROMIUM_PATH || '').trim();
const HEADLESS = toBoolean(process.env.WHATSAPP_PUPPETEER_HEADLESS, true);
const DISABLE_SANDBOX = toBoolean(process.env.WHATSAPP_PUPPETEER_DISABLE_SANDBOX, true);
const LAUNCH_TIMEOUT_MS = Number(process.env.WHATSAPP_PUPPETEER_LAUNCH_TIMEOUT_MS || 120000);
const MESSAGE_TIMEOUT_MS = Number(process.env.WHATSAPP_PUPPETEER_MESSAGE_TIMEOUT_MS || 45000);
const SEND_DELAY_MS = Number(process.env.WHATSAPP_PUPPETEER_SEND_DELAY_MS || 900);
const MAX_SEND_RETRIES = Number(process.env.WHATSAPP_PUPPETEER_MAX_SEND_RETRIES || 2);

const app = express();
app.use(express.json({ limit: '10mb' }));

let client = null;
let queueDepth = 0;
let sendQueue = Promise.resolve();
let lastQrPayload = null;
let reconnectTimer = null;

const status = {
  state: 'initializing',
  ready: false,
  message: 'Avvio del connettore WhatsApp in corso.',
  qrRequired: false,
  qrCodeDataUrl: null,
  webState: null,
  queueDepth: 0,
  phoneNumber: null,
  pushName: null,
  lastErrorCode: null,
  lastErrorMessage: null,
  lastEventAt: isoNow(),
  lastConnectedAt: null,
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

function updateStatus(next = {}) {
  Object.assign(status, next, {
    queueDepth,
    lastEventAt: isoNow(),
  });
}

function connectorResponse(extra = {}) {
  return {
    state: status.state,
    ready: status.ready,
    message: status.message,
    qr_required: status.qrRequired,
    qr_code_data_url: status.qrCodeDataUrl,
    web_state: status.webState,
    queue_depth: status.queueDepth,
    phone_number: status.phoneNumber,
    push_name: status.pushName,
    last_error_code: status.lastErrorCode,
    last_error_message: status.lastErrorMessage,
    last_event_at: status.lastEventAt,
    last_connected_at: status.lastConnectedAt,
    ...extra,
  };
}

function classifyError(error) {
  const message = String(error && error.message ? error.message : error || 'Errore tecnico sconosciuto');
  const lowered = message.toLowerCase();

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

async function ensureSessionDirectory() {
  await fsp.mkdir(SESSION_PATH, { recursive: true });
}

async function removeSessionDirectory() {
  if (!fs.existsSync(SESSION_PATH)) {
    return;
  }

  await fsp.rm(SESSION_PATH, { recursive: true, force: true });
}

function scheduleReconnect(delayMs = 5000) {
  if (reconnectTimer) {
    clearTimeout(reconnectTimer);
  }

  reconnectTimer = setTimeout(() => {
    reconnectTimer = null;
    initializeClient().catch(() => undefined);
  }, delayMs);
}

async function destroyClient() {
  if (!client) {
    return;
  }

  const currentClient = client;
  client = null;

  try {
    await currentClient.destroy();
  } catch (_) {
    // Best effort cleanup.
  }
}

async function initializeClient(options = {}) {
  const resetSession = Boolean(options.resetSession);

  if (resetSession) {
    await destroyClient();
    await removeSessionDirectory();
  }

  if (client) {
    return;
  }

  await ensureSessionDirectory();

  updateStatus({
    state: 'initializing',
    ready: false,
    qrRequired: false,
    qrCodeDataUrl: null,
    message: 'Avvio del connettore WhatsApp in corso.',
    lastErrorCode: null,
    lastErrorMessage: null,
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

  nextClient.on('qr', async (qr) => {
    lastQrPayload = qr;
    const qrCodeDataUrl = await QRCode.toDataURL(qr, { margin: 1, width: 280 });
    updateStatus({
      state: 'qr_required',
      ready: false,
      qrRequired: true,
      qrCodeDataUrl,
      message: 'QR richiesto: collega WhatsApp Web per abilitare il canale.',
      lastErrorCode: null,
      lastErrorMessage: null,
    });
  });

  nextClient.on('authenticated', () => {
    updateStatus({
      state: 'authenticated',
      ready: false,
      qrRequired: false,
      qrCodeDataUrl: null,
      message: 'Sessione autenticata, sincronizzazione WhatsApp in corso.',
    });
  });

  nextClient.on('ready', () => {
    const info = nextClient.info || null;
    lastQrPayload = null;
    updateStatus({
      state: 'connected',
      ready: true,
      qrRequired: false,
      qrCodeDataUrl: null,
      message: 'WhatsApp Web collegato e pronto all\'invio.',
      phoneNumber: info?.wid?.user || null,
      pushName: info?.pushname || null,
      lastConnectedAt: isoNow(),
      lastErrorCode: null,
      lastErrorMessage: null,
    });
  });

  nextClient.on('change_state', (webState) => {
    updateStatus({
      webState,
    });
  });

  nextClient.on('auth_failure', (message) => {
    updateStatus({
      state: 'session_expired',
      ready: false,
      qrRequired: false,
      qrCodeDataUrl: null,
      message: 'Sessione WhatsApp scaduta o non piu valida. Ricollega l\'account.',
      lastErrorCode: 'auth_failure',
      lastErrorMessage: String(message || 'Autenticazione WhatsApp fallita.'),
    });
    scheduleReconnect(4000);
  });

  nextClient.on('disconnected', (reason) => {
    updateStatus({
      state: 'session_expired',
      ready: false,
      qrRequired: false,
      qrCodeDataUrl: null,
      message: 'Connessione WhatsApp interrotta. Verificare la sessione o ricollegare l\'account.',
      lastErrorCode: 'disconnected',
      lastErrorMessage: String(reason || 'Connessione WhatsApp interrotta.'),
    });
    client = null;
    scheduleReconnect(4000);
  });

  nextClient.on('loading_screen', (_percent, message) => {
    updateStatus({
      state: 'initializing',
      ready: false,
      message: message ? `Preparazione WhatsApp Web: ${message}` : 'Preparazione WhatsApp Web in corso.',
    });
  });

  client = nextClient;

  try {
    const initPromise = nextClient.initialize();
    if (initPromise && typeof initPromise.catch === 'function') {
      initPromise.catch((error) => {
        const classification = classifyError(error);
        updateStatus({
          state: classification.connectorState,
          ready: false,
          qrRequired: false,
          qrCodeDataUrl: null,
          message: classification.friendlyMessage,
          lastErrorCode: classification.providerStatus,
          lastErrorMessage: String(error?.message || error),
        });
        client = null;
      });
    }
  } catch (error) {
    const classification = classifyError(error);
    updateStatus({
      state: classification.connectorState,
      ready: false,
      qrRequired: false,
      qrCodeDataUrl: null,
      message: classification.friendlyMessage,
      lastErrorCode: classification.providerStatus,
      lastErrorMessage: String(error?.message || error),
    });
    client = null;
    throw error;
  }
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
  if (!client) {
    initializeClient().catch(() => undefined);
  }

  res.json(connectorResponse({
    session_path: SESSION_PATH,
    last_qr_available: !!lastQrPayload,
  }));
});

app.post('/reconnect', authorizeRequest, async (req, res) => {
  const resetSession = Boolean(req.body?.reset_session);

  try {
    await initializeClient({ resetSession });
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

  if (!client) {
    initializeClient().catch(() => undefined);
  }

  const result = await enqueueSend({ target, message, subject, mediaPath, mediaBase64, mediaName, mediaMimeType });
  return res.status(result.delivery_status === 'sent' ? 200 : 200).json(result);
});

app.listen(CONNECTOR_PORT, CONNECTOR_HOST, async () => {
  try {
    await ensureSessionDirectory();
    initializeClient().catch(() => undefined);
    console.log(`[whatsapp-connector] listening on http://${CONNECTOR_HOST}:${CONNECTOR_PORT}`);
  } catch (error) {
    const classification = classifyError(error);
    updateStatus({
      state: classification.connectorState,
      ready: false,
      message: classification.friendlyMessage,
      lastErrorCode: classification.providerStatus,
      lastErrorMessage: String(error?.message || error),
    });
    console.error('[whatsapp-connector] startup error', error);
  }
});

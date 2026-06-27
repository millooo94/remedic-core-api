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
const ACK_POLL_INTERVAL_MS = Number(process.env.WHATSAPP_PUPPETEER_ACK_POLL_INTERVAL_MS || 500);
const BROWSER_UI_SEND_TIMEOUT_MS = Number(process.env.WHATSAPP_PUPPETEER_BROWSER_UI_SEND_TIMEOUT_MS || 90000);
const SEND_DELAY_MS = Number(process.env.WHATSAPP_PUPPETEER_SEND_DELAY_MS || 900);
const MAX_SEND_RETRIES = Number(process.env.WHATSAPP_PUPPETEER_MAX_SEND_RETRIES || 2);
const CONNECT_WAIT_MS = Number(process.env.WHATSAPP_PUPPETEER_CONNECT_WAIT_MS || 20000);
const CONNECT_POLL_MS = Number(process.env.WHATSAPP_PUPPETEER_CONNECT_POLL_MS || 300);
const SESSION_RESET_DELAY_MS = Number(process.env.WHATSAPP_PUPPETEER_SESSION_RESET_DELAY_MS || 700);
const CHROMIUM_SHUTDOWN_WAIT_MS = Number(process.env.WHATSAPP_PUPPETEER_CHROMIUM_SHUTDOWN_WAIT_MS || 1500);
const QR_TIMEOUT_MS = Number(process.env.WHATSAPP_PUPPETEER_QR_TIMEOUT_MS || 25000);
const CONNECTING_TIMEOUT_MS = Number(process.env.WHATSAPP_PUPPETEER_CONNECTING_TIMEOUT_MS || 60000);

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
let isResettingSession = false;
let isConnectingSession = false;
let qrWatchdog = null;
let connectingWatchdog = null;
const messageAckMap = new Map();

const SESSION_PROFILE_PATH = path.join(SESSION_PATH, `session-${CLIENT_ID}`);
const STALE_BROWSER_MARKERS = Array.from(new Set([
  SESSION_PATH,
  SESSION_PROFILE_PATH,
  `session-${CLIENT_ID}`,
  CLIENT_ID,
  path.basename(SESSION_PATH),
  'puppeteer_dev_chrome_profile',
])).filter(Boolean);
const STALE_BROWSER_COMMAND_PATTERNS = [
  /\/snap\/chromium\/.*\/chrome/i,
  /\bchromium-browser\b/i,
  /\bchrome_crashpad_handler\b/i,
  /\bcrashpad_handler\b/i,
];
const PROCESS_OWNER = String(process.env.USER || process.env.LOGNAME || '').trim();

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

function normalizeAckValue(value) {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }

  if (value === null || value === undefined || value === '') {
    return null;
  }

  const normalized = Number(value);
  return Number.isFinite(normalized) ? normalized : null;
}

function extractMessageId(message) {
  return message?.id?._serialized || null;
}

async function waitForAck(messageId, initialAck, minAck = 1, timeoutMs = MESSAGE_TIMEOUT_MS) {
  const normalizedInitialAck = normalizeAckValue(initialAck);

  if (!messageId) {
    return null;
  }

  if (normalizedInitialAck !== null && (normalizedInitialAck >= minAck || normalizedInitialAck < 0)) {
    return normalizedInitialAck;
  }

  const startedAt = Date.now();

  while ((Date.now() - startedAt) < timeoutMs) {
    const trackedAck = normalizeAckValue(messageAckMap.get(messageId));
    if (trackedAck !== null && (trackedAck >= minAck || trackedAck < 0)) {
      return trackedAck;
    }

    await wait(ACK_POLL_INTERVAL_MS);
  }

  return normalizeAckValue(messageAckMap.get(messageId)) ?? normalizedInitialAck;
}

async function getLatestOutgoingMessageId(chatIds = []) {
  if (!client?.pupPage) {
    return null;
  }

  try {
    return await client.pupPage.evaluate((targetChatIds) => {
      const collections = window.require?.('WAWebCollections');
      const msgCollection = collections?.Msg;
      if (!msgCollection?.models) {
        return null;
      }

      const normalizedChatIds = Array.isArray(targetChatIds)
        ? targetChatIds.filter(Boolean)
        : [targetChatIds].filter(Boolean);

      const matchesChat = (msg) => {
        const remote = msg?.id?.remote?._serialized || msg?.id?.remote || null;
        const to = msg?.to?._serialized || msg?.to || null;
        const chat = msg?.chatId?._serialized || msg?.chatId || null;

        return normalizedChatIds.some((chatId) => [remote, to, chat].includes(chatId));
      };

      const candidates = msgCollection.models
        .filter((msg) => msg?.id?.fromMe && matchesChat(msg))
        .sort((left, right) => Number(right?.t || 0) - Number(left?.t || 0));

      const latest = candidates[0]
        || msgCollection.models
          .filter((msg) => msg?.id?.fromMe)
          .sort((left, right) => Number(right?.t || 0) - Number(left?.t || 0))[0];
      return latest?.id?._serialized || null;
    }, chatIds);
  } catch (error) {
    logConnector('browser ui latest outgoing lookup failed', {
      chatIds,
      message: String(error?.message || error),
    });

    return null;
  }
}

async function getLatestOutgoingMessageIdByBody(messageText) {
  if (!client?.pupPage) {
    return null;
  }

  try {
    return await client.pupPage.evaluate((targetBody) => {
      const collections = window.require?.('WAWebCollections');
      const msgCollection = collections?.Msg;
      if (!msgCollection?.models) {
        return null;
      }

      const normalizedTarget = String(targetBody || '').trim();
      const candidates = msgCollection.models
        .filter((msg) => {
          if (!msg?.id?.fromMe) {
            return false;
          }

          const body = String(msg?.body || msg?.caption || '').trim();
          return normalizedTarget !== '' && body === normalizedTarget;
        })
        .sort((left, right) => Number(right?.t || 0) - Number(left?.t || 0));

      return candidates[0]?.id?._serialized || null;
    }, messageText);
  } catch (error) {
    logConnector('browser ui outgoing lookup by body failed', {
      message: String(error?.message || error),
    });

    return null;
  }
}

async function captureBrowserUiDiagnostics(step, selectors = []) {
  if (!client?.pupPage) {
    return null;
  }

  try {
    const diagnostics = await client.pupPage.evaluate((selectorList) => {
      const bodyText = (document.body?.innerText || '').replace(/\s+/g, ' ').trim();
      const selectorCounts = Object.fromEntries(
        selectorList.map((selector) => [selector, document.querySelectorAll(selector).length])
      );

      return {
        url: window.location.href,
        title: document.title,
        selector_counts: selectorCounts,
        body_snippet: bodyText.slice(0, 1000),
      };
    }, selectors);

    logConnector(`browser_ui_send:${step}`, diagnostics || {});
    return diagnostics;
  } catch (error) {
    logConnector(`browser_ui_send:${step}:diagnostics_failed`, {
      message: String(error?.message || error),
    });

    return null;
  }
}

async function collectVisibleWhatsAppUiErrors() {
  if (!client?.pupPage) {
    return null;
  }

  try {
    const texts = await client.pupPage.evaluate(() => {
      const selectors = [
        '[role="alert"]',
        '[data-testid="alert"]',
        '[data-testid="toast"]',
        '[aria-live="polite"]',
      ];

      return selectors
        .flatMap((selector) => Array.from(document.querySelectorAll(selector)))
        .map((node) => node.textContent?.trim() || '')
        .filter(Boolean)
        .slice(0, 5);
    });

    return Array.isArray(texts) && texts.length > 0 ? texts.join(' | ') : null;
  } catch (error) {
    logConnector('browser ui visible error lookup failed', {
      message: String(error?.message || error),
    });

    return null;
  }
}

async function browserUiSend({ normalizedTarget, message, hasMedia }) {
  if (hasMedia) {
    throw new Error('browser_ui_send non supporta media attachments.');
  }

  if (!client?.pupPage) {
    throw new Error('Pagina browser WhatsApp Web non disponibile.');
  }

  const page = client.pupPage;
  const encodedMessage = encodeURIComponent(message);
  const targetUrl = `https://web.whatsapp.com/send?phone=${encodeURIComponent(normalizedTarget)}&text=${encodedMessage}`;

  await page.goto(targetUrl, {
    waitUntil: 'domcontentloaded',
    timeout: MESSAGE_TIMEOUT_MS,
  });

  await page.waitForFunction(() => {
    const textbox = document.querySelector('footer [contenteditable="true"][role="textbox"]')
      || document.querySelector('div[contenteditable="true"][role="textbox"]');
    const invalidLabel = Array.from(document.querySelectorAll('div, span'))
      .some((node) => {
        const text = node.textContent?.trim().toLowerCase() || '';
        return text.includes('numero di telefono condiviso tramite url non è valido')
          || text.includes('phone number shared via url is invalid')
          || text.includes('impossibile inviare')
          || text.includes('couldn\'t send');
      });

    return Boolean(textbox) || invalidLabel;
  }, { timeout: MESSAGE_TIMEOUT_MS });

  const visibleError = await collectVisibleWhatsAppUiErrors();
  if (visibleError && /numero di telefono|phone number|impossibile inviare|couldn't send/i.test(visibleError)) {
    throw new Error(visibleError);
  }

  const textboxSelector = 'footer [contenteditable="true"][role="textbox"], div[contenteditable="true"][role="textbox"]';
  const textbox = await page.$(textboxSelector);
  if (!textbox) {
    throw new Error('Textbox WhatsApp Web non trovata per browser_ui_send.');
  }

  await textbox.click({ clickCount: 1 });
  await page.keyboard.press('Enter');
  await wait(500);

  const messageId = await getLatestOutgoingMessageId(`${normalizedTarget}@c.us`);
  if (!messageId) {
    throw new Error('Messaggio UI non rilevato dopo l invio da browser.');
  }

  const initialAck = normalizeAckValue(messageAckMap.get(messageId));
  const finalAck = await waitForAck(messageId, initialAck, 1, MESSAGE_TIMEOUT_MS);

  return {
    messageId,
    initialAck,
    finalAck,
    technicalMessage: visibleError,
  };
}

async function browserUiSendRobust({ normalizedTarget, message, hasMedia }) {
  if (hasMedia) {
    throw new Error('browser_ui_send non supporta media attachments.');
  }

  if (!client?.pupPage) {
    throw new Error('Pagina browser WhatsApp Web non disponibile.');
  }

  const page = client.pupPage;
  const encodedMessage = encodeURIComponent(message);
  const targetUrl = `https://web.whatsapp.com/send?phone=${encodeURIComponent(normalizedTarget)}&text=${encodedMessage}`;
  const textboxSelectors = [
    'footer [contenteditable="true"][role="textbox"]',
    'footer div[contenteditable="true"]',
    'div[contenteditable="true"][role="textbox"]',
    'div[contenteditable="true"][data-tab]',
    'div[contenteditable="true"][data-lexical-editor="true"]',
    '[aria-label*="Scrivi"]',
    '[aria-label*="messaggio"]',
    '[aria-label*="Type a message"]',
    '[aria-label*="Message"]',
  ];
  const shortOpenTimeoutMs = 6000;
  const composeAfterClickTimeoutMs = 15000;
  const chatHints = [
    normalizedTarget,
    normalizedTarget.slice(-10),
    normalizedTarget.slice(-8),
    message,
  ].filter(Boolean);

  const waitForComposeOrInvalid = async (timeoutMs) => {
    try {
      await page.waitForFunction((selectors) => {
        const nodes = selectors
          .map((selector) => document.querySelector(selector))
          .filter(Boolean);

        const invalidLabel = Array.from(document.querySelectorAll('div, span'))
          .some((node) => {
            const text = node.textContent?.trim().toLowerCase() || '';
            return text.includes('numero di telefono condiviso tramite url non è valido')
              || text.includes('numero di telefono condiviso tramite url non e valido')
              || text.includes('phone number shared via url is invalid')
              || text.includes('impossibile inviare')
              || text.includes('couldn\'t send')
              || text.includes('phone number is invalid');
          });

        const hasComposeBox = nodes.some((node) => {
          const inFooter = Boolean(node.closest('footer'));
          const ariaLabel = (node.getAttribute('aria-label') || '').toLowerCase();
          return inFooter
            || ariaLabel.includes('scrivi')
            || ariaLabel.includes('messaggio')
            || ariaLabel.includes('type a message')
            || ariaLabel.includes('message');
        });

        return hasComposeBox || invalidLabel;
      }, { timeout: timeoutMs }, textboxSelectors);

      return true;
    } catch {
      return false;
    }
  };

  const findComposeTextbox = async () => {
    for (const selector of textboxSelectors) {
      const candidate = await page.$(selector);
      if (!candidate) {
        continue;
      }

      const isComposeBox = await candidate.evaluate((node) => {
        const inFooter = Boolean(node.closest('footer'));
        const ariaLabel = (node.getAttribute('aria-label') || '').toLowerCase();
        return inFooter
          || ariaLabel.includes('scrivi')
          || ariaLabel.includes('messaggio')
          || ariaLabel.includes('type a message')
          || ariaLabel.includes('message');
      });

      if (isComposeBox) {
        return candidate;
      }
    }

    return null;
  };

  const clickChatFromHome = async () => {
    const chatRow = await page.evaluateHandle((hints) => {
      const normalizedHints = hints
        .map((hint) => String(hint || '').trim().toLowerCase())
        .filter(Boolean);

      const candidates = Array.from(document.querySelectorAll('div[role="listitem"], [data-testid="cell-frame-container"], [data-testid="chat-list-item"]'));
      const matched = candidates.find((node) => {
        const text = (node.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        return normalizedHints.some((hint) => text.includes(hint));
      });

      return matched || null;
    }, chatHints);

    const element = chatRow.asElement();
    if (!element) {
      return false;
    }

    const rowText = await element.evaluate((node) => (node.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 300));
    logConnector('browser_ui_send:chat_row_found', {
      phone: normalizedTarget,
      row_text: rowText,
    });

    await element.click();
    logConnector('browser_ui_send:chat_row_clicked', {
      phone: normalizedTarget,
    });

    return true;
  };

  logConnector('browser_ui_send:before_goto', {
    phone: normalizedTarget,
    message_length: message.length,
    target_url: `https://web.whatsapp.com/send?phone=${encodeURIComponent(normalizedTarget)}&text=<${message.length}_chars>`,
  });

  await page.goto(targetUrl, {
    waitUntil: 'domcontentloaded',
    timeout: BROWSER_UI_SEND_TIMEOUT_MS,
  });

  await captureBrowserUiDiagnostics('after_goto', textboxSelectors);
  const openedDirectly = await waitForComposeOrInvalid(shortOpenTimeoutMs);
  let textbox = await findComposeTextbox();

  if (!openedDirectly || !textbox) {
    const diagnostics = await captureBrowserUiDiagnostics('redirected_home', textboxSelectors);
    const redirectedHome = diagnostics?.url === 'https://web.whatsapp.com/' && (diagnostics?.body_snippet || '').length > 0;

    if (redirectedHome) {
      logConnector('browser_ui_send:redirected_home', {
        phone: normalizedTarget,
        url: diagnostics?.url,
        title: diagnostics?.title,
      });

      const chatClicked = await clickChatFromHome();
      if (!chatClicked) {
        throw new Error(
          `WhatsApp Web redirected to home and compose textbox was not available | url=${diagnostics?.url || 'n/a'} | title=${diagnostics?.title || 'n/a'} | selectors=${JSON.stringify(diagnostics?.selector_counts || {})} | body=${diagnostics?.body_snippet || 'n/a'}`
        );
      }

      const composeAfterClick = await waitForComposeOrInvalid(composeAfterClickTimeoutMs);
      if (!composeAfterClick) {
        const afterClickDiagnostics = await captureBrowserUiDiagnostics('compose_not_found_after_click', textboxSelectors);
        logConnector('browser_ui_send:compose_not_found_after_click', {
          phone: normalizedTarget,
          url: afterClickDiagnostics?.url,
          title: afterClickDiagnostics?.title,
        });
        throw new Error(
          `WhatsApp Web redirected to home and compose textbox was not available | url=${afterClickDiagnostics?.url || 'n/a'} | title=${afterClickDiagnostics?.title || 'n/a'} | selectors=${JSON.stringify(afterClickDiagnostics?.selector_counts || {})} | body=${afterClickDiagnostics?.body_snippet || 'n/a'}`
        );
      }

      textbox = await findComposeTextbox();
      if (textbox) {
        logConnector('browser_ui_send:compose_found_after_click', {
          phone: normalizedTarget,
        });
      }
    } else {
      const waitingDiagnostics = await captureBrowserUiDiagnostics('waiting_textbox_timeout', textboxSelectors);
      throw new Error(
        `Waiting failed: compose textbox not available | url=${waitingDiagnostics?.url || 'n/a'} | title=${waitingDiagnostics?.title || 'n/a'} | body=${waitingDiagnostics?.body_snippet || 'n/a'}`
      );
    }
  }

  await captureBrowserUiDiagnostics('waiting_textbox', textboxSelectors);

  const visibleError = await collectVisibleWhatsAppUiErrors();
  if (visibleError && /numero di telefono|phone number|impossibile inviare|couldn't send/i.test(visibleError)) {
    throw new Error(visibleError);
  }

  if (!textbox) {
    const diagnostics = await captureBrowserUiDiagnostics('textbox_not_found', textboxSelectors);
    throw new Error(
      `Textbox WhatsApp Web non trovata per browser_ui_send. url=${diagnostics?.url || 'n/a'} title=${diagnostics?.title || 'n/a'}`
    );
  }

  logConnector('browser_ui_send:textbox_found', {
    phone: normalizedTarget,
  });

  await textbox.click({ clickCount: 1 });

  const textboxContent = await textbox.evaluate((node) => (node.innerText || node.textContent || '').trim());
  if (textboxContent !== message) {
    await page.keyboard.down(process.platform === 'darwin' ? 'Meta' : 'Control');
    await page.keyboard.press('KeyA');
    await page.keyboard.up(process.platform === 'darwin' ? 'Meta' : 'Control');
    await page.keyboard.press('Backspace');
    await page.keyboard.type(message, { delay: 20 });
  }

  logConnector('browser_ui_send:send_pressed', {
    phone: normalizedTarget,
  });

  await page.keyboard.press('Enter');
  await wait(750);

  let messageId = await getLatestOutgoingMessageId([
    `${normalizedTarget}@c.us`,
    `${normalizedTarget}@s.whatsapp.net`,
    `${normalizedTarget}@lid`,
    status.phoneNumber ? `${status.phoneNumber}@c.us` : null,
  ].filter(Boolean));

  if (!messageId) {
    messageId = await getLatestOutgoingMessageIdByBody(message);
  }

  if (!messageId) {
    const diagnostics = await captureBrowserUiDiagnostics('message_id_not_found', textboxSelectors);
    throw new Error(
      `Messaggio UI non rilevato dopo l invio da browser. url=${diagnostics?.url || 'n/a'} title=${diagnostics?.title || 'n/a'}`
    );
  }

  logConnector('browser_ui_send:message_id_found', {
    phone: normalizedTarget,
    messageId,
  });

  const initialAck = normalizeAckValue(messageAckMap.get(messageId));
  const finalAck = await waitForAck(messageId, initialAck, 1, BROWSER_UI_SEND_TIMEOUT_MS);

  return {
    messageId,
    initialAck,
    finalAck,
    technicalMessage: visibleError,
  };
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

function isRecoveringState(rawState) {
  return [
    'automation_unavailable',
    'browser_locked',
    'session_cleanup_failed',
    'connecting_timeout',
    'stale_authenticated_session',
    'qr_timeout',
  ].includes(String(rawState || ''));
}

function deriveNormalizedState() {
  if (status.ready && status.state === 'connected') {
    return queueDepth > 0 ? 'sending' : 'ready';
  }

  if (status.qrRequired && status.qrCodeDataUrl) {
    return 'qr_required';
  }

  switch (String(status.state || '')) {
    case 'starting':
    case 'initializing':
    case 'waiting_for_scan':
      return 'starting';
    case 'authenticated':
    case 'connecting':
      return 'authenticated';
    case 'connected':
      return queueDepth > 0 ? 'sending' : (status.ready ? 'ready' : 'authenticated');
    case 'disconnected':
    case 'session_expired':
    case 'auth_failure':
      return 'disconnected';
    case 'browser_locked':
    case 'session_cleanup_failed':
    case 'automation_unavailable':
    case 'connecting_timeout':
    case 'stale_authenticated_session':
    case 'qr_timeout':
      return 'recovering';
    case 'browser_unavailable':
    case 'ui_incompatible':
    case 'technical_error':
    case 'error':
      return 'error';
    default:
      return status.ready ? 'ready' : 'error';
  }
}

function setOperation(name) {
  currentOperation = name;
}

function clearQrWatchdog() {
  if (qrWatchdog) {
    clearTimeout(qrWatchdog);
    qrWatchdog = null;
  }
}

function clearConnectingWatchdog() {
  if (connectingWatchdog) {
    clearTimeout(connectingWatchdog);
    connectingWatchdog = null;
  }
}

function connectorResponse(extra = {}) {
  const normalizedState = deriveNormalizedState();

  return {
    state: status.state,
    normalized_state: normalizedState,
    ready: status.ready,
    can_send: isConnectorReady(),
    is_recovering: isRecoveringState(status.state) || normalizedState === 'recovering',
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
  return isResettingSession
    || isConnectingSession
    || currentOperation === 'connect'
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
  clearQrWatchdog();
  clearConnectingWatchdog();
  isConnectingSession = false;
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
  clearQrWatchdog();
  clearConnectingWatchdog();
  isConnectingSession = false;
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
    const result = await execFileAsync('ps', ['-eo', 'pid=,user=,command=']);
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
      const match = line.match(/^(\d+)\s+(\S+)\s+(.*)$/);
      if (!match) {
        return null;
      }

      return {
        pid: Number(match[1]),
        user: match[2],
        command: match[3],
      };
    })
    .filter((entry) => entry && entry.pid > 0)
    .filter((entry) => /chrom(e|ium)|chrome-headless-shell|chrome_crashpad_handler|crashpad_handler/i.test(entry.command))
    .filter((entry) => {
      const commandMatchesMarker = STALE_BROWSER_MARKERS.some((marker) => entry.command.includes(marker));
      const commandMatchesSnapPattern = STALE_BROWSER_COMMAND_PATTERNS.some((pattern) => pattern.test(entry.command));
      const matchesSameUser = PROCESS_OWNER !== '' && entry.user === PROCESS_OWNER;

      if (commandMatchesMarker) {
        return true;
      }

      return matchesSameUser && commandMatchesSnapPattern;
    });

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
  isResettingSession = true;

  try {
    if (!skipDestroy) {
      await safeDestroyClient(reason);
    }

    await cleanupStaleChromiumProcesses();
    await wait(SESSION_RESET_DELAY_MS);
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
  } finally {
    isResettingSession = false;
  }
}

async function hasLocalAuthSession() {
  const localAuthPath = path.join(SESSION_PATH, `session-${CLIENT_ID}`);

  try {
    const entries = await fsp.readdir(localAuthPath);
    return entries.length > 0;
  } catch (_) {
    return false;
  }
}

async function ensureConnectorEnvironment() {
  await assertSessionDirectoryWritable();

  if (CHROMIUM_PATH && !fs.existsSync(CHROMIUM_PATH)) {
    throw new Error(`Chromium executable not found at ${CHROMIUM_PATH}`);
  }
}

async function recoverFromTransientSessionState({
  generation,
  nextState,
  message,
  errorCode,
  reason,
}) {
  if (generation !== activeClientGeneration) {
    return false;
  }

  const targetClient = client;
  isConnectingSession = false;
  currentInitPromise = null;
  clearQrWatchdog();
  clearConnectingWatchdog();
  lastQrPayload = null;

  updateStatus({
    state: nextState,
    ready: false,
    qrRequired: false,
    qrCodeDataUrl: null,
    qrUpdatedAt: null,
    message,
    webState: null,
    phoneNumber: null,
    pushName: null,
    lastErrorCode: errorCode,
    lastErrorMessage: message,
  });

  logConnector(reason, {
    generation,
    nextState,
    errorCode,
    message,
  });

  try {
    if (targetClient) {
      await safeDestroyClient(reason, targetClient);
    }

    await cleanupStaleChromiumProcesses();
    await wait(SESSION_RESET_DELAY_MS);
    await fsp.rm(SESSION_PATH, { recursive: true, force: true });
    await ensureSessionDirectory();
    await assertSessionDirectoryWritable();
  } catch (error) {
    setCleanupFailedStatus('Impossibile pulire la sessione WhatsApp dopo un collegamento non completato.', error);
    return false;
  }

  updateStatus({
    state: nextState,
    ready: false,
    qrRequired: false,
    qrCodeDataUrl: null,
    qrUpdatedAt: null,
    message,
    webState: null,
    phoneNumber: null,
    pushName: null,
    lastErrorCode: errorCode,
    lastErrorMessage: message,
  });

  return true;
}

function startQrWatchdog(generation) {
  clearQrWatchdog();

  qrWatchdog = setTimeout(() => {
    runSerializedConnectorOperation(async () => {
      if (generation !== activeClientGeneration) {
        return;
      }

      if (
        status.ready
        || status.qrRequired
        || [
          'connected',
          'qr_required',
          'session_expired',
          'browser_unavailable',
          'browser_locked',
          'session_cleanup_failed',
          'ui_incompatible',
          'technical_error',
          'error',
          'qr_timeout',
          'connecting_timeout',
          'stale_authenticated_session',
        ].includes(status.state)
      ) {
        return;
      }

      await recoverFromTransientSessionState({
        generation,
        nextState: 'qr_timeout',
        errorCode: 'qr_timeout',
        message: 'QR non generato. Riprova collegamento.',
        reason: 'qr timeout reached',
      });
    }).catch((error) => {
      logConnector('qr watchdog cleanup failed', {
        generation,
        message: String(error && error.message ? error.message : error),
      });
    });
  }, QR_TIMEOUT_MS);
}

function startConnectingWatchdog(generation) {
  clearConnectingWatchdog();

  connectingWatchdog = setTimeout(() => {
    runSerializedConnectorOperation(async () => {
      if (generation !== activeClientGeneration) {
        return;
      }

      if (status.ready || !['connecting', 'authenticated'].includes(status.state)) {
        return;
      }

      await recoverFromTransientSessionState({
        generation,
        nextState: 'connecting_timeout',
        errorCode: 'stale_authenticated_session',
        message: 'La sessione WhatsApp non si e completata. Genera un nuovo QR.',
        reason: 'connecting watchdog timeout reached',
      });
    }).catch((error) => {
      logConnector('connecting watchdog cleanup failed', {
        generation,
        message: String(error && error.message ? error.message : error),
      });
    });
  }, CONNECTING_TIMEOUT_MS);
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
  isConnectingSession = true;
  messageAckMap.clear();

  activeClientGeneration += 1;
  const generation = activeClientGeneration;
  clearQrWatchdog();
  clearConnectingWatchdog();

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

    clearQrWatchdog();
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

    clearQrWatchdog();
    updateStatus({
      state: 'authenticated',
      ready: false,
      qrRequired: false,
      qrCodeDataUrl: null,
      qrUpdatedAt: null,
      message: 'Verifica sessione WhatsApp in corso...',
    });
    startConnectingWatchdog(generation);
    logConnector('authenticated', {
      generation,
    });
  });

  nextClient.on('ready', () => {
    if (handleStaleEvent('ready')) {
      return;
    }

    clearQrWatchdog();
    clearConnectingWatchdog();
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

  nextClient.on('message_ack', (message, ack) => {
    if (handleStaleEvent('message_ack', { messageId: extractMessageId(message), ack })) {
      return;
    }

    const messageId = extractMessageId(message);
    if (!messageId) {
      return;
    }

    messageAckMap.set(messageId, normalizeAckValue(ack));
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

    clearQrWatchdog();
    clearConnectingWatchdog();
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

    clearQrWatchdog();
    clearConnectingWatchdog();
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

    if (!status.qrRequired && ['starting', 'waiting_for_scan'].includes(status.state)) {
      updateStatus({
        state: 'waiting_for_scan',
        ready: false,
        message: message ? `Preparazione WhatsApp Web: ${message}` : 'Preparazione WhatsApp Web in corso.',
      });
    }
    logConnector('loading_screen', {
      generation,
      message: String(message || ''),
    });
  });

  client = nextClient;
  startQrWatchdog(generation);

  const initPromise = (async () => {
    try {
      await nextClient.initialize();
    } catch (error) {
      const classification = classifyError(error);

      if (classification.connectorState === 'browser_locked') {
        await cleanupStaleChromiumProcesses();
      }

      if (isActiveClient(nextClient, generation)) {
        clearQrWatchdog();
        clearConnectingWatchdog();
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
    } finally {
      if (generation === activeClientGeneration) {
        isConnectingSession = false;
      }
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

    if (['browser_unavailable', 'browser_locked', 'session_cleanup_failed', 'ui_incompatible', 'technical_error', 'session_expired', 'error', 'qr_timeout', 'connecting_timeout', 'stale_authenticated_session'].includes(status.state)) {
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
    logConnector('send blocked - connector not ready', {
      normalizedState: deriveNormalizedState(),
      qrRequired: status.qrRequired,
      queueDepth,
    });

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

  logConnector('send requested', {
    targetTail: normalizedTarget.slice(-4),
    hasMedia,
    queueDepth,
  });

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

      const lookupChatId = numberId._serialized;
      const sendChatId = `${normalizedTarget}@c.us`;
      const attempts = [];

      const sendPayload = async (chatId) => {
        if (hasMedia && media) {
          return withTimeout(
            client.sendMessage(chatId, media, {
              caption: message,
            }),
            MESSAGE_TIMEOUT_MS,
            'Send WhatsApp timeout'
          );
        }

        return withTimeout(
          client.sendMessage(chatId, message),
          MESSAGE_TIMEOUT_MS,
          'Send WhatsApp timeout'
        );
      };

      const executeAttempt = async (attemptMethod, senderFactory) => {
        const sentMessage = await senderFactory();
        const messageId = extractMessageId(sentMessage);
        const initialAck = normalizeAckValue(sentMessage?.ack);
        const finalAck = await waitForAck(messageId, initialAck, 1, MESSAGE_TIMEOUT_MS);

        const attemptResult = {
          attempt_method: attemptMethod,
          lookup_chat_id: lookupChatId,
          send_chat_id: attemptMethod === 'direct_c_us' ? sendChatId : (sentMessage?.to || lookupChatId || sendChatId),
          message_id: messageId,
          initial_ack: initialAck,
          final_ack: finalAck,
        };

        attempts.push(attemptResult);

        logConnector('send success', {
          targetTail: normalizedTarget.slice(-4),
          hasMedia,
          lookupChatId,
          sendChatId: attemptResult.send_chat_id,
          attemptMethod,
          messageId,
        });

        return { sentMessage, ...attemptResult };
      };

      const tryResolveFallbackChat = async () => {
        const fallbackResolvers = [
          {
            attemptMethod: 'chat_c_us',
            resolve: async () => client.getChatById(sendChatId),
          },
          {
            attemptMethod: 'chat_lookup_lid',
            resolve: async () => client.getChatById(lookupChatId),
          },
          {
            attemptMethod: 'contact_chat',
            resolve: async () => {
              try {
                const contact = await client.getContactById(sendChatId);
                if (contact) {
                  return await contact.getChat();
                }
              } catch {}

              const lookupContact = await client.getContactById(lookupChatId);
              return lookupContact ? lookupContact.getChat() : null;
            },
          },
        ];

        for (const resolver of fallbackResolvers) {
          try {
            const chat = await withTimeout(
              Promise.resolve(resolver.resolve()),
              MESSAGE_TIMEOUT_MS,
              'Resolve WhatsApp fallback chat timeout'
            );

            if (!chat || typeof chat.sendMessage !== 'function') {
              continue;
            }

            return {
              attemptMethod: resolver.attemptMethod,
              chat,
            };
          } catch (error) {
            logConnector('send fallback resolver failed', {
              targetTail: normalizedTarget.slice(-4),
              lookupChatId,
              sendChatId,
              attemptMethod: resolver.attemptMethod,
              message: String(error?.message || error),
            });
          }
        }

        return null;
      };

      let directAttempt = null;
      try {
        directAttempt = await executeAttempt('direct_c_us', () => sendPayload(sendChatId));
      } catch (primarySendError) {
        logConnector('send primary destination failed', {
          targetTail: normalizedTarget.slice(-4),
          lookupChatId,
          sendChatId,
          fallbackAllowed: lookupChatId !== sendChatId,
          message: String(primarySendError?.message || primarySendError),
        });

        throw primarySendError;
      }

      const buildBaseResponse = (attemptResult) => ({
        chat_id: attemptResult.send_chat_id,
        lookup_chat_id: lookupChatId,
        send_chat_id: attemptResult.send_chat_id,
        ack: attemptResult.final_ack,
        initial_ack: attemptResult.initial_ack,
        final_ack: attemptResult.final_ack,
        from_me: directAttempt?.sentMessage?.fromMe ?? true,
        media_sent: hasMedia,
      });

      if (directAttempt.final_ack !== null && directAttempt.final_ack >= 1) {
        return {
          delivery_status: 'sent',
          provider_status: 'sent',
          message_id: directAttempt.message_id,
          error_message: null,
          response: {
            ...buildBaseResponse(directAttempt),
            attempts,
            fallback_used: false,
            attempt_method: 'direct_c_us',
          },
          sent_at: isoNow(),
        };
      }

      if (directAttempt.final_ack !== null && directAttempt.final_ack < 0) {
        const fallbackResolution = await tryResolveFallbackChat();

        if (fallbackResolution) {
          const fallbackAttempt = await executeAttempt(
            fallbackResolution.attemptMethod,
            () => withTimeout(
              hasMedia && media
                ? fallbackResolution.chat.sendMessage(media, { caption: message })
                : fallbackResolution.chat.sendMessage(message),
              MESSAGE_TIMEOUT_MS,
              'Send WhatsApp fallback timeout'
            )
          );

          if (fallbackAttempt.final_ack !== null && fallbackAttempt.final_ack >= 1) {
            return {
              delivery_status: 'sent',
              provider_status: 'sent',
              message_id: fallbackAttempt.message_id,
              error_message: null,
              response: {
                ...buildBaseResponse(fallbackAttempt),
                attempts,
                fallback_used: true,
                attempt_method: fallbackAttempt.attempt_method,
              },
              sent_at: isoNow(),
            };
          }

          if (fallbackAttempt.final_ack === null || fallbackAttempt.final_ack === 0) {
            return {
              delivery_status: 'failed',
              provider_status: 'send_not_confirmed',
              message_id: fallbackAttempt.message_id,
              error_message: 'WhatsApp non ha confermato l invio del messaggio.',
              response: {
                ...buildBaseResponse(fallbackAttempt),
                attempts,
                fallback_used: true,
                attempt_method: fallbackAttempt.attempt_method,
              },
              sent_at: null,
            };
          }
        }

        try {
          const browserUiAttemptRaw = await browserUiSendRobust({
            normalizedTarget,
            message,
            hasMedia,
          });

          const browserUiAttempt = {
            attempt_method: 'browser_ui_send',
            lookup_chat_id: lookupChatId,
            send_chat_id: `${normalizedTarget}@c.us`,
            message_id: browserUiAttemptRaw.messageId,
            initial_ack: browserUiAttemptRaw.initialAck,
            final_ack: browserUiAttemptRaw.finalAck,
          };

          attempts.push(browserUiAttempt);

          if (browserUiAttempt.final_ack !== null && browserUiAttempt.final_ack >= 1) {
            return {
              delivery_status: 'sent',
              provider_status: 'sent',
              message_id: browserUiAttempt.message_id,
              error_message: null,
              response: {
                ...buildBaseResponse(browserUiAttempt),
                attempts,
                fallback_used: true,
                attempt_method: browserUiAttempt.attempt_method,
              },
              sent_at: isoNow(),
            };
          }

          if (browserUiAttempt.final_ack === null || browserUiAttempt.final_ack === 0) {
            return {
              delivery_status: 'failed',
              provider_status: 'send_not_confirmed',
              message_id: browserUiAttempt.message_id,
              error_message: 'WhatsApp non ha confermato l invio del messaggio.',
              response: {
                ...buildBaseResponse(browserUiAttempt),
                attempts,
                fallback_used: true,
                attempt_method: browserUiAttempt.attempt_method,
                technical_message: browserUiAttemptRaw.technicalMessage || null,
              },
              sent_at: null,
            };
          }

          return {
            delivery_status: 'failed',
            provider_status: 'send_error',
            message_id: browserUiAttempt.message_id,
            error_message: 'WhatsApp ha restituito un errore durante l invio del messaggio.',
            response: {
              ...buildBaseResponse(browserUiAttempt),
              attempts,
              fallback_used: true,
              attempt_method: browserUiAttempt.attempt_method,
              technical_message: browserUiAttemptRaw.technicalMessage || null,
            },
            sent_at: null,
          };
        } catch (browserUiError) {
          attempts.push({
            attempt_method: 'browser_ui_send',
            lookup_chat_id: lookupChatId,
            send_chat_id: `${normalizedTarget}@c.us`,
            message_id: null,
            initial_ack: null,
            final_ack: null,
            technical_message: String(browserUiError?.message || browserUiError),
          });
        }

        return {
          delivery_status: 'failed',
          provider_status: 'send_error',
          message_id: directAttempt.message_id,
          error_message: 'WhatsApp ha restituito un errore durante l invio del messaggio.',
          response: {
            ...buildBaseResponse(directAttempt),
            attempts,
            fallback_used: true,
            attempt_method: directAttempt.attempt_method,
            technical_message: attempts[attempts.length - 1]?.technical_message || null,
          },
          sent_at: null,
        };
      }

      return {
        delivery_status: 'failed',
        provider_status: 'send_not_confirmed',
        message_id: directAttempt.message_id,
        error_message: 'WhatsApp non ha confermato l invio del messaggio.',
        response: {
          ...buildBaseResponse(directAttempt),
          attempts,
          fallback_used: false,
          attempt_method: directAttempt.attempt_method,
        },
        sent_at: null,
      };
    } catch (error) {
      const classification = classifyError(error);
      const retryable = classification.providerStatus === 'timeout' || classification.providerStatus === 'technical_error';

      logConnector('send failed', {
        targetTail: normalizedTarget.slice(-4),
        attempt,
        retryable,
        providerStatus: classification.providerStatus,
        connectorState: classification.connectorState,
        message: String(error?.message || error),
      });

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
    has_local_auth_session: await hasLocalAuthSession(),
    has_persisted_session: await hasPersistedSessionData(),
  }));
});

app.post('/connect', authorizeRequest, async (req, res) => {
  const explicitResetSession = req.body?.reset_session;
  const resetSession = explicitResetSession === undefined
    ? !(await hasLocalAuthSession())
    : Boolean(explicitResetSession);

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

    if (waitResult.kind === 'timeout') {
      await runSerializedConnectorOperation(() => recoverFromTransientSessionState({
        generation,
        nextState: 'qr_timeout',
        errorCode: 'qr_timeout',
        message: 'QR non generato. Riprova collegamento.',
        reason: 'connect wait timed out before qr generation',
      }));
    }

    const responseWaitResult = waitResult.kind === 'timeout'
      ? (status.state === 'session_cleanup_failed' ? 'session_cleanup_failed' : 'qr_timeout')
      : waitResult.kind;
    const responseMessage = waitResult.kind === 'qr_ready'
      ? 'Scansiona il QR code con WhatsApp.'
      : waitResult.kind === 'connected'
        ? 'WhatsApp collegato correttamente.'
        : waitResult.kind === 'timeout'
          ? status.message || 'QR non generato. Riprova collegamento.'
          : waitResult.message || 'Generazione di un nuovo QR code WhatsApp avviata.';

    res.status(202).json(connectorResponse({
      requested_reset_session: Boolean(resetSession),
      wait_result: responseWaitResult,
      message: responseMessage,
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
      const clientToLogout = client;

      if (clientToLogout && typeof clientToLogout.logout === 'function') {
        try {
          logConnector('logout client requested', { reason: 'manual_disconnect' });
          await clientToLogout.logout();
          logConnector('logout client completed', { reason: 'manual_disconnect' });
        } catch (error) {
          logConnector('logout client failed', {
            reason: 'manual_disconnect',
            message: String(error && error.message ? error.message : error),
          });
        }
      }

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
  clearQrWatchdog();
  clearConnectingWatchdog();

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

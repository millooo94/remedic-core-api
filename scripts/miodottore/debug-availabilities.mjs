import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { chromium } from 'playwright';

const CALENDAR_URL = 'https://docplanner.miodottore.it/#/calendar-clinic/day';
const CALENDAR_EVENTS_PATH = '/api/calendarevents';

async function main() {
  const options = parseArgs(process.argv.slice(2));
  validateOptions(options);

  await ensureDir(options.outputDir);
  await ensureDir(path.join(options.outputDir, 'screenshots'));

  const networkCalls = [];

  await writeJson(options.outputDir, '00-start.json', {
    provider: 'miodottore',
    source: 'api/calendarevents',
    login_url: options.loginUrl,
    verify_url: options.verifyUrl,
    calendar_url: CALENDAR_URL,
    from: options.from,
    to: options.to,
    days: options.days,
    doctor: options.doctor,
    output_dir: options.outputDir,
    started_at: new Date().toISOString(),
    headless: options.headless,
  });

  const browser = await chromium.launch({
    headless: options.headless,
    executablePath: options.chromiumPath || undefined,
    args: ['--window-size=1440,960'],
  });

  const context = await browser.newContext({
    viewport: { width: 1440, height: 960 },
    storageState: options.statePath,
  });

  const page = await context.newPage();
  const calendarEventsTracker = trackCalendarEvents(page, networkCalls);

  const result = {
    success: false,
    message: 'Lettura disponibilita MioDottore completata con warning.',
    warnings: [],
  };

  try {
    const firstCalendarRequestPromise = page.waitForRequest(
      (request) => request.url().includes(CALENDAR_EVENTS_PATH),
      { timeout: Math.min(options.timeoutMs, 30000) },
    );

    await page.goto(CALENDAR_URL, {
      waitUntil: 'domcontentloaded',
      timeout: options.timeoutMs,
    });
    await page.waitForTimeout(6000);

    const accessCheck = await analyzeAccess(page, options.loginUrl);
    await writeJson(options.outputDir, '01-access-check.json', accessCheck);
    await page.screenshot({ path: path.join(options.outputDir, 'screenshots', '01-access-check.png'), fullPage: true }).catch(() => undefined);

    if (!accessCheck.success) {
      throw new Error('Sessione MioDottore non valida. Ricollega MioDottore dalla pagina Integrazioni.');
    }

    const initialCalendarRequest = await firstCalendarRequestPromise.catch(() => null);
    if (initialCalendarRequest === null) {
      throw new Error('MioDottore non ha eseguito la chiamata iniziale api/calendarevents. Controlla gli artefatti di debug.');
    }

    const requestUrl = initialCalendarRequest.url();
    const requestHeaders = buildReplayHeaders(initialCalendarRequest.headers());
    const requestPayload = {
      from: options.from,
      to: buildRequestTo(options.to),
      schedules: [],
    };

    await writeJson(options.outputDir, '02-calendarevents.request.json', {
      url: requestUrl,
      method: 'POST',
      payload: requestPayload,
      replay_headers: sanitizeHeadersForArtifact(requestHeaders),
      template_request: {
        payload: safeParseJson(initialCalendarRequest.postData()) ?? initialCalendarRequest.postData(),
        header_names: Object.keys(initialCalendarRequest.headers()).sort(),
      },
    });

    const calendarEventsResponse = await fetchCalendarEvents(page, requestUrl, requestHeaders, requestPayload);
    if (!calendarEventsResponse.ok) {
      throw new Error(`Chiamata MioDottore api/calendarevents fallita con status ${calendarEventsResponse.status}.`);
    }

    const rawCalendarEvents = safeParseJson(calendarEventsResponse.bodyText);
    if (!rawCalendarEvents || typeof rawCalendarEvents !== 'object') {
      throw new Error('Risposta MioDottore api/calendarevents non valida o non JSON.');
    }

    await writeJson(options.outputDir, '03-calendarevents.raw.json', rawCalendarEvents);

    const normalizedSchedules = normalizeSchedules(rawCalendarEvents, options.doctor);
    await writeJson(options.outputDir, '04-schedules.normalized.json', normalizedSchedules);

    const normalizedWorkperiods = normalizeWorkperiods(rawCalendarEvents, normalizedSchedules.items);
    await writeJson(options.outputDir, '05-workperiods.normalized.json', normalizedWorkperiods);

    const normalizedAvailabilities = normalizeAvailabilities(rawCalendarEvents, normalizedSchedules.items, options);
    await writeJson(options.outputDir, '06-availabilities.normalized.json', normalizedAvailabilities);
    await writeJson(options.outputDir, 'network-calls.json', networkCalls);

    result.success = true;
    const declaredAvailabilityCount = normalizedAvailabilities.summary.weekly_hours_count
      + normalizedAvailabilities.summary.daily_available_exceptions_count;

    result.message = declaredAvailabilityCount > 0
      ? 'Lettura disponibilita dichiarate MioDottore completata da api/calendarevents.'
      : 'Lettura disponibilita dichiarate MioDottore completata da api/calendarevents. Nessuna disponibilita positiva prodotta.';
    result.warnings = normalizedAvailabilities.warnings;
    result.professionals_count = normalizedAvailabilities.professionals.length;
    result.declared_availability_segments_count = declaredAvailabilityCount;
    result.analyzed_days_count = normalizedAvailabilities.summary.normalized_days_count;
    result.summary = normalizedAvailabilities.summary;
  } catch (error) {
    result.success = false;
    result.message = error instanceof Error ? error.message : 'Lettura disponibilita MioDottore fallita.';
    result.warnings = [result.message];
    await safeWriteFallbackArtifacts(page, options.outputDir);
    await writeJson(options.outputDir, 'network-calls.json', networkCalls).catch(() => undefined);
    process.exitCode = 1;
  } finally {
    await writeJson(options.outputDir, 'result.json', result);
    await browser.close().catch(() => undefined);
  }
}

function trackCalendarEvents(page, collector) {
  page.on('response', (response) => {
    if (!response.url().includes(CALENDAR_EVENTS_PATH)) {
      return;
    }

    void captureCalendarEventsResponse(response, collector);
  });

  return collector;
}

async function captureCalendarEventsResponse(response, collector) {
  const request = response.request();
  const entry = {
    captured_at: new Date().toISOString(),
    method: request.method(),
    url: response.url(),
    status: response.status(),
    request_payload: safeParseJson(request.postData()) ?? request.postData(),
    request_headers: sanitizeHeadersForArtifact(buildReplayHeaders(request.headers())),
  };

  try {
    const json = await response.json();
    entry.response_preview = summarizeCalendarEvents(json);
  } catch {
    entry.response_preview = null;
  }

  collector.push(entry);
}

async function analyzeAccess(page, loginUrl) {
  const currentUrl = page.url();
  const host = safeParseUrl(currentUrl)?.host ?? '';
  const pathname = safeParseUrl(currentUrl)?.pathname?.toLowerCase() ?? '';
  const loginHost = safeParseUrl(loginUrl)?.host ?? '';
  const hasLoginForm = await page.locator('input[type="password"]').first().isVisible().catch(() => false);
  const isAppSelectionPage = host === 'l.miodottore.it' && pathname.startsWith('/apps');
  const isInternalApp = host === 'docplanner.miodottore.it';
  const isLoginPage = hasLoginForm || host === loginHost;

  return {
    success: isInternalApp || isAppSelectionPage,
    final_url: currentUrl,
    host,
    title: await safePageTitle(page),
    is_internal_app: isInternalApp,
    is_app_selection_page: isAppSelectionPage,
    is_login_page: isLoginPage,
    has_login_form: hasLoginForm,
  };
}

async function fetchCalendarEvents(page, url, headers, payload) {
  return page.evaluate(async ({ requestUrl, requestHeaders, requestPayload }) => {
    const response = await fetch(requestUrl, {
      method: 'POST',
      credentials: 'include',
      headers: requestHeaders,
      body: JSON.stringify(requestPayload),
    });

    return {
      ok: response.ok,
      status: response.status,
      statusText: response.statusText,
      url: response.url,
      bodyText: await response.text(),
    };
  }, {
    requestUrl: url,
    requestHeaders: headers,
    requestPayload: payload,
  });
}

function buildReplayHeaders(headers) {
  const allowedHeaders = [
    'authorization',
    'x-user-type',
    'x-one-front-version',
    'one-user-id',
    'x-clinic-size',
    'x-country-id',
    'accept',
    'content-type',
  ];

  return Object.fromEntries(
    allowedHeaders
      .map((name) => [name, headers[name]])
      .filter(([, value]) => typeof value === 'string' && value.length > 0)
      .map(([name, value]) => [name, name === 'content-type' ? 'application/json' : value]),
  );
}

function sanitizeHeadersForArtifact(headers) {
  const sanitized = { ...headers };

  if (typeof sanitized.authorization === 'string' && sanitized.authorization !== '') {
    sanitized.authorization = `${sanitized.authorization.slice(0, 16)}...redacted`;
  }

  return sanitized;
}

function normalizeSchedules(raw, doctorFilter) {
  const schedulesObject = isPlainObject(raw.schedules) ? raw.schedules : {};
  const resources = Array.isArray(raw.resources) ? raw.resources : [];
  const workperiods = Array.isArray(raw.workperiods) ? raw.workperiods : [];
  const appointments = Array.isArray(raw.appointments) ? raw.appointments : [];

  const resourceMap = new Map(resources.map((resource) => [String(resource.id), resource]));
  const scheduleIds = new Set([
    ...Object.keys(schedulesObject),
    ...resources.map((resource) => String(resource.id)),
    ...workperiods.map((workperiod) => String(workperiod.scheduleId)),
    ...appointments.map((appointment) => String(appointment.scheduleId)),
  ]);

  const items = [...scheduleIds]
    .map((scheduleId) => buildScheduleItem(scheduleId, schedulesObject[String(scheduleId)], resourceMap.get(String(scheduleId))))
    .filter(Boolean)
    .filter((item) => matchesDoctorFilter(item, doctorFilter))
    .sort((left, right) => String(left.display_name).localeCompare(String(right.display_name), 'it'));

  return {
    requested_doctor: doctorFilter,
    count: items.length,
    items,
  };
}

function buildScheduleItem(scheduleId, schedule, resource) {
  const numericScheduleId = toNullableNumber(schedule?.id ?? resource?.id ?? scheduleId);
  if (numericScheduleId === null) {
    return null;
  }

  return {
    provider_schedule_id: numericScheduleId,
    provider_doctor_id: toNullableNumber(schedule?.doctorId ?? resource?.doctorId),
    provider_name: coalesceString(schedule?.name, schedule?.displayName, resource?.title, `Schedule ${numericScheduleId}`),
    display_name: coalesceString(schedule?.displayName, resource?.title, schedule?.name, `Schedule ${numericScheduleId}`),
    specialty_id: toNullableNumber(schedule?.specialityId ?? resource?.specialityId),
    specialty_name: coalesceString(resource?.specialityName, null),
    facility_id: toNullableNumber(schedule?.facilityId ?? resource?.facilityId),
    facility_name: coalesceString(resource?.facilityName, null),
    raw_schedule: schedule ?? null,
    raw_resource: resource ?? null,
  };
}

function normalizeWorkperiods(raw, schedules) {
  const scheduleIds = new Set(schedules.map((schedule) => schedule.provider_schedule_id));
  const items = (Array.isArray(raw.workperiods) ? raw.workperiods : [])
    .filter((workperiod) => scheduleIds.has(toNullableNumber(workperiod.scheduleId)))
    .map((workperiod) => {
      const parts = splitIsoDateTime(workperiod.start);
      const endParts = splitIsoDateTime(workperiod.end);

      return {
        schedule_id: toNullableNumber(workperiod.scheduleId),
        date: parts.date,
        start: parts.time,
        end: endParts.time,
        is_private: Boolean(workperiod.isPrivate),
      };
    })
    .filter((item) => item.schedule_id !== null && item.date !== null && item.start !== null && item.end !== null);

  return {
    count: items.length,
    items,
  };
}

function normalizeAvailabilities(raw, schedules, options) {
  const scheduleIds = new Set(schedules.map((schedule) => schedule.provider_schedule_id));
  const workperiods = (Array.isArray(raw.workperiods) ? raw.workperiods : [])
    .filter((workperiod) => scheduleIds.has(toNullableNumber(workperiod.scheduleId)));
  const appointments = (Array.isArray(raw.appointments) ? raw.appointments : [])
    .filter((appointment) => scheduleIds.has(toNullableNumber(appointment.scheduleId)));
  const blocks = (Array.isArray(raw.blocks) ? raw.blocks : [])
    .filter((block) => blockAppliesToAnySchedule(block, schedules));

  const professionals = schedules.map((schedule) => {
    const daysMap = new Map();

    for (const workperiod of workperiods) {
      if (toNullableNumber(workperiod.scheduleId) !== schedule.provider_schedule_id) {
        continue;
      }

      const date = splitIsoDateTime(workperiod.start).date;
      if (date === null) {
        continue;
      }

      const day = ensureDay(daysMap, date);
      pushWorkperiod(day.workperiods, workperiod);
    }

    for (const appointment of appointments) {
      if (toNullableNumber(appointment.scheduleId) !== schedule.provider_schedule_id) {
        continue;
      }

      const date = splitIsoDateTime(appointment.start).date;
      if (date === null) {
        continue;
      }

      const day = ensureDay(daysMap, date);
      pushAppointment(day.appointments, appointment);
    }

    for (const block of blocks) {
      if (!blockAppliesToSchedule(block, schedule)) {
        continue;
      }

      const date = splitIsoDateTime(block.start).date;
      if (date === null) {
        continue;
      }

      const day = ensureDay(daysMap, date);
      pushBlock(day.blocks, block);
    }

    const days = [...daysMap.entries()]
      .sort(([leftDate], [rightDate]) => leftDate.localeCompare(rightDate))
      .map(([, day]) => ({
        date: day.date,
        weekday: weekdayFromDate(day.date),
        workperiods: mergeTimeRanges(day.workperiods),
        appointments: sortRanges(day.appointments),
        blocks: sortRanges(day.blocks),
      }))
      .filter((day) => (
        day.workperiods.length > 0
        || day.appointments.length > 0
        || day.blocks.length > 0
      ));

    const declaredAvailability = deriveDeclaredAvailability(days, schedule.provider_schedule_id);

    return {
      provider_schedule_id: schedule.provider_schedule_id,
      provider_doctor_id: schedule.provider_doctor_id,
      provider_name: schedule.provider_name,
      display_name: schedule.display_name,
      specialty_id: schedule.specialty_id,
      specialty_name: schedule.specialty_name,
      facility_id: schedule.facility_id,
      facility_name: schedule.facility_name,
      days,
      weekly_hours: declaredAvailability.weekly_hours,
      orari_settimanali: declaredAvailability.weekly_hours,
      daily_available_exceptions: declaredAvailability.daily_available_exceptions,
      eccezioni_disponibilita: declaredAvailability.daily_available_exceptions,
      appointments: declaredAvailability.appointments,
      appuntamenti: declaredAvailability.appointments,
      ignored_unavailable_blocks: declaredAvailability.ignored_unavailable_blocks,
      blocchi_non_disponibilita_ignorati: declaredAvailability.ignored_unavailable_blocks,
    };
  });

  const summary = {
    schedules_count: schedules.length,
    workperiods_count: workperiods.length,
    appointments_count: appointments.length,
    blocks_count: countRelevantBlocks(blocks, schedules),
    normalized_days_count: professionals.reduce((total, professional) => total + professional.days.length, 0),
    weekly_hours_count: professionals.reduce((total, professional) => total + professional.weekly_hours.length, 0),
    daily_available_exceptions_count: professionals.reduce((total, professional) => total + professional.daily_available_exceptions.length, 0),
    ignored_unavailable_blocks_count: professionals.reduce((total, professional) => total + professional.ignored_unavailable_blocks.length, 0),
  };

  const warnings = [];
  if (schedules.length === 0) {
    warnings.push(options.doctor
      ? `Nessuna agenda MioDottore trovata per il filtro medico "${options.doctor}".`
      : 'Nessuna agenda MioDottore trovata nella risposta api/calendarevents.');
  }
  if (summary.workperiods_count === 0) {
    warnings.push('Nessun workperiod trovato nella risposta api/calendarevents per i filtri richiesti.');
  }
  if (summary.weekly_hours_count === 0 && summary.daily_available_exceptions_count === 0) {
    warnings.push('Nessuna disponibilita dichiarata prodotta: verifica workperiods negli artefatti di debug.');
  }

  return {
    provider: 'miodottore',
    from: options.from,
    to: options.to,
    source: 'api/calendarevents',
    summary,
    professionals,
    warnings,
  };
}

function ensureDay(daysMap, date) {
  if (!daysMap.has(date)) {
    daysMap.set(date, {
      date,
      workperiods: [],
      appointments: [],
      blocks: [],
    });
  }

  return daysMap.get(date);
}

function pushWorkperiod(target, workperiod) {
  const start = splitIsoDateTime(workperiod.start).time;
  const end = splitIsoDateTime(workperiod.end).time;
  if (start === null || end === null) {
    return;
  }

  target.push({
    start,
    end,
  });
}

function pushAppointment(target, appointment) {
  const start = splitIsoDateTime(appointment.start).time;
  const end = splitIsoDateTime(appointment.end).time;
  if (start === null || end === null) {
    return;
  }

  target.push({
    id: toNullableNumber(appointment.id),
    schedule_id: toNullableNumber(appointment.scheduleId),
    start,
    end,
    service_name: coalesceString(appointment.serviceName, null),
    status: appointment.status ?? null,
  });
}

function pushBlock(target, block) {
  const start = splitIsoDateTime(block.start).time;
  const end = splitIsoDateTime(block.end).time;
  if (start === null || end === null) {
    return;
  }

  target.push({
    id: toNullableNumber(block.id),
    start,
    end,
    comments: coalesceString(block.comments, null),
    all_day: Boolean(block.allDay),
  });
}

function sortRanges(ranges) {
  return [...ranges].sort((left, right) => (
    toMinutes(left.start) - toMinutes(right.start)
    || toMinutes(left.end) - toMinutes(right.end)
  ));
}

function mergeTimeRanges(ranges) {
  const merged = mergeIntervals(ranges.map(toInterval));

  return merged.map((range) => ({
    start: minutesToTime(range.start),
    end: minutesToTime(range.end),
  }));
}

function mergeIntervals(intervals) {
  const sorted = intervals
    .filter((interval) => interval !== null && interval.end > interval.start)
    .sort((left, right) => left.start - right.start || left.end - right.end);

  const merged = [];

  for (const interval of sorted) {
    const last = merged.at(-1);
    if (!last || interval.start > last.end) {
      merged.push({ ...interval });
      continue;
    }

    last.end = Math.max(last.end, interval.end);
  }

  return merged;
}

function toInterval(range) {
  const start = toMinutes(range.start);
  const end = toMinutes(range.end);
  if (start === null || end === null) {
    return null;
  }

  return { start, end };
}

function blockAppliesToAnySchedule(block, schedules) {
  return schedules.some((schedule) => blockAppliesToSchedule(block, schedule));
}

function blockAppliesToSchedule(block, schedule) {
  const relatedSchedules = Array.isArray(block.relatedSchedules) ? block.relatedSchedules.map(toNullableNumber).filter((value) => value !== null) : [];
  if (relatedSchedules.length > 0) {
    return relatedSchedules.includes(schedule.provider_schedule_id);
  }

  const blockDoctorId = toNullableNumber(block.doctorId);
  if (blockDoctorId !== null && schedule.provider_doctor_id !== null) {
    return blockDoctorId === schedule.provider_doctor_id;
  }

  return false;
}

function countRelevantBlocks(blocks, schedules) {
  return blocks.filter((block) => blockAppliesToAnySchedule(block, schedules)).length;
}

function deriveDeclaredAvailability(days, providerScheduleId) {
  const occurrenceMap = new Map();

  for (const day of days) {
    for (const workperiod of day.workperiods) {
      const key = `${day.weekday}|${workperiod.start}|${workperiod.end}`;
      const current = occurrenceMap.get(key) ?? {
        weekday: day.weekday,
        start: workperiod.start,
        end: workperiod.end,
        occurrences: 0,
      };

      current.occurrences += 1;
      occurrenceMap.set(key, current);
    }
  }

  const weeklyHours = [...occurrenceMap.values()]
    .filter((item) => item.occurrences > 1)
    .sort((left, right) => (
      weekdaySortValue(left.weekday) - weekdaySortValue(right.weekday)
      || toMinutes(left.start) - toMinutes(right.start)
      || toMinutes(left.end) - toMinutes(right.end)
    ));

  const weeklyKeys = new Set(
    weeklyHours.map((item) => `${item.weekday}|${item.start}|${item.end}`),
  );

  const dailyAvailableExceptions = days.flatMap((day) => (
    day.workperiods
      .filter((workperiod) => !weeklyKeys.has(`${day.weekday}|${workperiod.start}|${workperiod.end}`))
      .map((workperiod) => ({
        date: day.date,
        weekday: day.weekday,
        start: workperiod.start,
        end: workperiod.end,
      }))
  )).sort((left, right) => (
    left.date.localeCompare(right.date)
    || toMinutes(left.start) - toMinutes(right.start)
    || toMinutes(left.end) - toMinutes(right.end)
  ));

  const ignoredUnavailableBlocks = days.flatMap((day) => (
    day.blocks.map((block) => ({
      schedule_id: providerScheduleId,
      date: day.date,
      start: block.start,
      end: block.end,
      label: block.comments ?? null,
      all_day: Boolean(block.all_day),
    }))
  )).sort((left, right) => (
    left.date.localeCompare(right.date)
    || toMinutes(left.start) - toMinutes(right.start)
    || toMinutes(left.end) - toMinutes(right.end)
  ));

  const normalizedAppointments = days.flatMap((day) => (
    day.appointments.map((appointment) => ({
      date: day.date,
      start: appointment.start,
      end: appointment.end,
      service_name: appointment.service_name ?? null,
      status: appointment.status ?? null,
      schedule_id: appointment.schedule_id ?? providerScheduleId,
    }))
  )).sort((left, right) => (
    left.date.localeCompare(right.date)
    || toMinutes(left.start) - toMinutes(right.start)
    || toMinutes(left.end) - toMinutes(right.end)
  ));

  return {
    weekly_hours: weeklyHours,
    daily_available_exceptions: dailyAvailableExceptions,
    ignored_unavailable_blocks: ignoredUnavailableBlocks,
    appointments: normalizedAppointments,
  };
}

function weekdayFromDate(date) {
  if (typeof date !== 'string' || date.length !== 10) {
    return 'unknown';
  }

  const weekdayNames = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
  const day = new Date(`${date}T12:00:00Z`).getUTCDay();

  return weekdayNames[day] ?? 'unknown';
}

function weekdaySortValue(weekday) {
  const order = new Map([
    ['monday', 1],
    ['tuesday', 2],
    ['wednesday', 3],
    ['thursday', 4],
    ['friday', 5],
    ['saturday', 6],
    ['sunday', 7],
  ]);

  return order.get(weekday) ?? 99;
}

function matchesDoctorFilter(schedule, doctorFilter) {
  if (!doctorFilter) {
    return true;
  }

  const haystacks = [
    schedule.provider_name,
    schedule.display_name,
    schedule.raw_schedule?.name,
    schedule.raw_schedule?.displayName,
    schedule.raw_resource?.title,
  ].filter((value) => typeof value === 'string');

  return haystacks.some((value) => value.toLowerCase().includes(doctorFilter.toLowerCase()));
}

function summarizeCalendarEvents(data) {
  return {
    schedules_count: isPlainObject(data?.schedules) ? Object.keys(data.schedules).length : 0,
    appointments_count: Array.isArray(data?.appointments) ? data.appointments.length : 0,
    blocks_count: Array.isArray(data?.blocks) ? data.blocks.length : 0,
    holidays_count: Array.isArray(data?.holidays) ? data.holidays.length : 0,
    workperiods_count: Array.isArray(data?.workperiods) ? data.workperiods.length : 0,
    resources_count: Array.isArray(data?.resources) ? data.resources.length : 0,
  };
}

async function safeWriteFallbackArtifacts(page, outputDir) {
  const pageState = {
    current_url: page.url(),
    title: await safePageTitle(page),
    visible_text: await getVisibleText(page),
  };

  await writeJson(outputDir, '02-page-state.json', pageState).catch(() => undefined);
  await page.screenshot({ path: path.join(outputDir, 'screenshots', 'error.png'), fullPage: true }).catch(() => undefined);
}

async function getVisibleText(page) {
  return (await page.locator('body').innerText().catch(() => '')).trim().slice(0, 8000);
}

async function safePageTitle(page) {
  return page.title().catch(() => '');
}

function buildRequestTo(value) {
  return `${value}T23:59:59`;
}

function splitIsoDateTime(value) {
  if (typeof value !== 'string') {
    return { date: null, time: null };
  }

  const match = value.match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/);
  if (!match) {
    return { date: null, time: null };
  }

  return {
    date: match[1],
    time: match[2],
  };
}

function toMinutes(value) {
  if (typeof value !== 'string') {
    return null;
  }

  const match = value.match(/^(\d{2}):(\d{2})$/);
  if (!match) {
    return null;
  }

  return (Number.parseInt(match[1], 10) * 60) + Number.parseInt(match[2], 10);
}

function minutesToTime(value) {
  const hours = Math.floor(value / 60);
  const minutes = value % 60;

  return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
}

function safeParseJson(value) {
  if (typeof value !== 'string' || value.trim() === '') {
    return null;
  }

  try {
    return JSON.parse(value);
  } catch {
    return null;
  }
}

function toNullableNumber(value) {
  if (value === null || value === undefined || value === '') {
    return null;
  }

  const number = Number(value);

  return Number.isFinite(number) ? number : null;
}

function coalesceString(...values) {
  for (const value of values) {
    if (typeof value === 'string' && value.trim() !== '') {
      return value.trim();
    }
  }

  return null;
}

function isPlainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function safeParseUrl(value) {
  try {
    return new URL(value);
  } catch {
    return null;
  }
}

async function writeJson(outputDir, fileName, payload) {
  await fs.writeFile(path.join(outputDir, fileName), JSON.stringify(payload, null, 2), 'utf8');
}

async function ensureDir(targetPath) {
  await fs.mkdir(targetPath, { recursive: true });
}

function parseArgs(args) {
  const options = {
    loginUrl: '',
    verifyUrl: '',
    statePath: '',
    outputDir: '',
    from: '',
    to: '',
    days: 30,
    doctor: null,
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
      case '--from':
        options.from = next ?? '';
        index += 1;
        break;
      case '--to':
        options.to = next ?? '';
        index += 1;
        break;
      case '--days':
        options.days = Number.parseInt(next ?? '30', 10);
        index += 1;
        break;
      case '--doctor':
        options.doctor = next ?? null;
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
  if (!options.from) missing.push('from');
  if (!options.to) missing.push('to');

  if (missing.length > 0) {
    throw new Error(`Configurazione debug disponibilita incompleta: mancano ${missing.join(', ')}.`);
  }
}

void main();

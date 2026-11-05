/** UI-only projection. Server event timestamps remain UTC, never rewrite them. */
const DEFAULT_TIMEZONE = 'America/Argentina/Cordoba';
let displayTimezone = DEFAULT_TIMEZONE;

export function setDisplayTimezone(zone: unknown): void {
  if (typeof zone !== 'string') return;
  try {
    // Reject unknown identifiers even if the server returns invalid configuration.
    new Intl.DateTimeFormat('es-AR', { timeZone: zone });
    displayTimezone = zone;
  } catch { displayTimezone = DEFAULT_TIMEZONE; }
}

export function getDisplayTimezone(): string { return displayTimezone; }

/** ISO with timezone is authoritative; legacy UTC strings without an offset get Z. */
export function parseUtcTimestamp(value: string): Date | null {
  if (!/^\d{4}-\d\d-\d\d[T ]\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)?$/i.test(value)) return null;
  const year = Number(value.slice(0, 4));
  const month = Number(value.slice(5, 7));
  const day = Number(value.slice(8, 10));
  const calendar = new Date(Date.UTC(year, month - 1, day));
  if (calendar.getUTCFullYear() !== year || calendar.getUTCMonth() + 1 !== month || calendar.getUTCDate() !== day) return null;
  const normalized = value.replace(' ', 'T');
  const date = new Date(/(?:Z|[+-]\d\d:\d\d)$/i.test(normalized) ? normalized : normalized + 'Z');
  return Number.isNaN(date.getTime()) ? null : date;
}

export function formatDateTime(value: string | null | undefined): string {
  const date = value ? parseUtcTimestamp(value) : null;
  return date ? new Intl.DateTimeFormat('es-AR', {
    dateStyle: 'short', timeStyle: 'short', timeZone: displayTimezone, hourCycle: 'h23',
  }).format(date) : 'Sin información';
}

export function formatClock(value: string | null | undefined): string {
  const date = value ? parseUtcTimestamp(value) : null;
  return date ? new Intl.DateTimeFormat('es-AR', {
    hour: '2-digit', minute: '2-digit', timeZone: displayTimezone, hourCycle: 'h23',
  }).format(date) : 'Sin información';
}

export function chartDateFormatter(options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat {
  return new Intl.DateTimeFormat('es-AR', { ...options, timeZone: displayTimezone, hourCycle: 'h23' });
}

/** Process start times are UNIX seconds, unlike ISO timestamps from Laravel. Zero is an unknown sentinel. */
export function formatUnixSeconds(seconds: number | null | undefined): string {
  if (typeof seconds !== 'number' || !Number.isFinite(seconds) || seconds <= 0) return 'Sin información';
  const stamp = new Date(seconds * 1000);
  return Number.isNaN(stamp.getTime()) ? 'Sin información' : formatDateTime(stamp.toISOString());
}

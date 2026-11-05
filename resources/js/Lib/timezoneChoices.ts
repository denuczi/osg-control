/** Readable choices only. The server still validates the canonical IANA ID. */
export type TimezoneChoice = { id: string; country: string };

const cityNames: Record<string, string> = {
  'America/Argentina/Cordoba': 'Córdoba',
  'America/Argentina/Buenos_Aires': 'Buenos Aires',
  'America/Bogota': 'Bogotá',
  'America/Mexico_City': 'Ciudad de México',
  'America/Cancun': 'Cancún',
  'America/New_York': 'Nueva York · zona este',
  'America/Chicago': 'Chicago · zona central',
  'America/Denver': 'Denver · zona de montaña',
  'America/Los_Angeles': 'Los Ángeles · zona oeste',
  'America/Anchorage': 'Anchorage · Alaska',
  'Pacific/Honolulu': 'Honolulu · Hawái',
  'Europe/Madrid': 'Madrid · España peninsular',
  'Atlantic/Canary': 'Islas Canarias',
};

export function countryName(code: string): string {
  if (code === 'ZZ') return 'Hora universal y otras zonas';
  try {
    return new Intl.DisplayNames(['es'], { type: 'region' }).of(code) || code;
  } catch { return code; }
}

export function cityName(id: string): string {
  if (id === 'UTC') return 'UTC · Hora universal';
  if (cityNames[id]) return cityNames[id];
  const segments = id.split('/').slice(1).map(segment => segment.replaceAll('_', ' '));
  return segments.length ? segments.join(' · ') : id;
}

export function countryOf(choices: TimezoneChoice[], selected: string): string {
  return choices.find(choice => choice.id === selected)?.country ?? 'ZZ';
}

/** The server sends canonical values; no browser-derived offset is stored. */
export function currentTimeInZone(zone: string, now: Date): string {
  try {
    return new Intl.DateTimeFormat('es', {
      dateStyle: 'medium', timeStyle: 'short', timeZone: zone, hourCycle: 'h23',
    }).format(now);
  } catch { return 'Hora no disponible'; }
}

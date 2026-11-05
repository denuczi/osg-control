import assert from 'node:assert/strict';
import { setDisplayTimezone, getDisplayTimezone, formatDateTime, formatClock, parseUtcTimestamp } from '../../resources/js/Lib/displayTimezone.ts';

setDisplayTimezone('America/Argentina/Cordoba');
assert.match(formatDateTime('2026-11-04T01:26:00Z'), /3\/11\/26, 22:26/);
assert.equal(formatClock('2026-11-04 01:26:00'), '22:26');
assert.equal(parseUtcTimestamp('2026-11-04 01:26:00')?.toISOString(), '2026-11-04T01:26:00.000Z');
setDisplayTimezone('Europe/Madrid');
assert.match(formatDateTime('2026-11-04T01:26:00Z'), /4\/11\/26, 02:26/);
setDisplayTimezone('America/New_York');
assert.match(formatDateTime('2026-03-08T06:30:00Z'), /1:30/);
assert.match(formatDateTime('2026-03-08T07:30:00Z'), /3:30/);
assert.equal(parseUtcTimestamp('2026-02-30T01:26:00Z'), null);
assert.equal(parseUtcTimestamp('no hay datos'), null);
setDisplayTimezone('invalid/zone');
assert.equal(getDisplayTimezone(), 'America/Argentina/Cordoba');
console.log('OK: zona IANA, UTC, fechas inválidas y DST');

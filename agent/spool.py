"""Durable local outbox. Each write commits before it is offered to Laravel."""
from __future__ import annotations

import json
from contextlib import contextmanager
import sqlite3
import uuid
from datetime import datetime, timezone, timedelta
from pathlib import Path


MAX_QUEUED_SAMPLES = 20000  # Bounded backlog: avoid filling the workstation disk.
MAX_STORAGE_BYTES = 256 * 1024 * 1024


class LocalSpool:
    def __init__(self, path: Path):
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self._init()

    @contextmanager
    def _connection(self):
        db = sqlite3.connect(self.path, timeout=15)
        db.execute("PRAGMA busy_timeout=15000")
        db.execute("PRAGMA journal_mode=WAL")
        db.execute("PRAGMA synchronous=FULL")
        try:
            with db:
                yield db
        finally:
            db.close()

    def _init(self):
        with self._connection() as db:
            db.execute("""CREATE TABLE IF NOT EXISTS samples (
                sequence INTEGER PRIMARY KEY AUTOINCREMENT,
                sample_uuid TEXT NOT NULL UNIQUE,
                captured_at TEXT NOT NULL,
                payload TEXT NOT NULL,
                retry_after TEXT
            )""")
            columns = {row[1] for row in db.execute("PRAGMA table_info(samples)")}
            if "retry_after" not in columns:
                db.execute("ALTER TABLE samples ADD COLUMN retry_after TEXT")
            db.execute("""CREATE TABLE IF NOT EXISTS inventory_outbox (
                slot INTEGER PRIMARY KEY CHECK(slot=1),
                captured_at TEXT NOT NULL,
                payload TEXT NOT NULL
            )""")
            db.execute("""CREATE TABLE IF NOT EXISTS media_state (
                slot INTEGER PRIMARY KEY CHECK(slot=1),
                payload TEXT NOT NULL
            )""")
            db.execute("""CREATE TABLE IF NOT EXISTS media_events (
                sequence INTEGER PRIMARY KEY AUTOINCREMENT,
                event_uuid TEXT NOT NULL UNIQUE,
                observed_at TEXT NOT NULL,
                kind TEXT NOT NULL,
                volume TEXT NOT NULL,
                retry_after TEXT
            )""")
            media_columns = {row[1] for row in db.execute("PRAGMA table_info(media_events)")}
            if "retry_after" not in media_columns:
                db.execute("ALTER TABLE media_events ADD COLUMN retry_after TEXT")
            db.execute("""CREATE TABLE IF NOT EXISTS command_receipts (
                command_uuid TEXT PRIMARY KEY,
                status TEXT NOT NULL,
                message TEXT NOT NULL,
                acknowledged INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )""")
            db.execute("""CREATE TABLE IF NOT EXISTS usage_baselines (
                interface_name TEXT PRIMARY KEY,
                boot_id TEXT NOT NULL, rx_counter INTEGER NOT NULL,
                tx_counter INTEGER NOT NULL, observed_at TEXT NOT NULL
            )""")
            db.execute("""CREATE TABLE IF NOT EXISTS usage_hours (
                bucket_uuid TEXT PRIMARY KEY, interface_name TEXT NOT NULL,
                boot_id TEXT NOT NULL, hour_utc TEXT NOT NULL,
                rx_bytes INTEGER NOT NULL DEFAULT 0,
                tx_bytes INTEGER NOT NULL DEFAULT 0,
                observed_seconds INTEGER NOT NULL DEFAULT 0,
                revision INTEGER NOT NULL DEFAULT 0,
                synced_revision INTEGER NOT NULL DEFAULT 0,
                retry_after TEXT
            )""")
            usage_columns = {row[1] for row in db.execute('PRAGMA table_info(usage_hours)')}
            if 'retry_after' not in usage_columns:
                db.execute('ALTER TABLE usage_hours ADD COLUMN retry_after TEXT')
            db.execute('CREATE INDEX IF NOT EXISTS usage_hours_pending ON usage_hours(hour_utc)')
            db.execute("""CREATE TABLE IF NOT EXISTS diagnostics_outbox (
                slot INTEGER PRIMARY KEY CHECK(slot=1),
                captured_at TEXT NOT NULL, payload TEXT NOT NULL
            )""")
            db.execute("""CREATE TABLE IF NOT EXISTS system_event_outbox (
                event_uuid TEXT PRIMARY KEY,
                payload TEXT NOT NULL, observed_at TEXT NOT NULL
            )""")
            db.execute("""CREATE TABLE IF NOT EXISTS system_event_seen (
                event_uuid TEXT PRIMARY KEY, observed_at TEXT NOT NULL
            )""")
            db.execute("CREATE INDEX IF NOT EXISTS system_seen_date ON system_event_seen(observed_at)")
            db.execute("""CREATE TABLE IF NOT EXISTS system_event_bookmarks (
                channel TEXT PRIMARY KEY, record_id INTEGER NOT NULL CHECK(record_id >= 0)
            )""")
            system_columns = {row[1] for row in db.execute('PRAGMA table_info(system_event_outbox)')}
            if 'retry_after' not in system_columns:
                db.execute('ALTER TABLE system_event_outbox ADD COLUMN retry_after TEXT')
            # Process handles cannot survive an agent restart. Never invent a click.
            db.execute("""UPDATE command_receipts SET status='unknown',
                message='Agente reiniciado antes de confirmar el mensaje.'
                WHERE status='awaiting_user'""")


    def save_diagnostics(self, payload: dict) -> None:
        """Only latest health state matters; retain until matching ACK."""
        captured_at = payload.get('captured_at')
        if not isinstance(captured_at, str):
            raise ValueError('Diagnóstico sin fecha')
        raw = json.dumps(payload, ensure_ascii=False, separators=(',', ':'))
        if len(raw.encode('utf-8')) > 45000:
            raise ValueError('Diagnóstico demasiado grande')
        with self._connection() as db:
            db.execute('INSERT OR REPLACE INTO diagnostics_outbox(slot,captured_at,payload) VALUES(1,?,?)',
                       (captured_at, raw))

    def pending_diagnostics(self) -> dict | None:
        with self._connection() as db:
            row = db.execute('SELECT payload FROM diagnostics_outbox WHERE slot=1').fetchone()
        return json.loads(row[0]) if row else None

    def acknowledge_diagnostics(self, captured_at: str) -> None:
        with self._connection() as db:
            db.execute('DELETE FROM diagnostics_outbox WHERE slot=1 AND captured_at=?', (captured_at,))

    def system_event_bookmarks(self) -> dict[str, int]:
        with self._connection() as db:
            return dict(db.execute('SELECT channel,record_id FROM system_event_bookmarks'))

    def record_system_events(self, events: list[dict], *, advances: dict[str, int] | None = None,
                             resets: set[str] | None = None, max_pending: int = 3000) -> int:
        """Commit events and source cursors atomically; do not lose unread events."""
        if len(events) > 200:
            raise ValueError('Demasiados eventos por captura')
        advances = advances or {}
        resets = resets or set()
        added = 0
        with self._connection() as db:
            for channel in resets:
                if channel not in ('System', 'Application'):
                    raise ValueError('Canal de eventos inválido')
                db.execute('DELETE FROM system_event_bookmarks WHERE channel=?', (channel,))
            for event in events:
                key = f"{event['channel']}|{event['record_id']}|{event['observed_at']}"
                event_uuid = str(uuid.uuid5(uuid.NAMESPACE_URL, 'nodo-event|' + key))
                if db.execute('SELECT 1 FROM system_event_seen WHERE event_uuid=?', (event_uuid,)).fetchone():
                    continue
                if db.execute('SELECT COUNT(*) FROM system_event_outbox').fetchone()[0] >= max_pending:
                    raise OverflowError('Cola de eventos llena: se conservan los pendientes')
                raw = json.dumps({**event, 'event_uuid': event_uuid}, ensure_ascii=False, separators=(',', ':'))
                db.execute('INSERT INTO system_event_outbox(event_uuid,payload,observed_at) VALUES(?,?,?)',
                           (event_uuid, raw, event['observed_at']))
                db.execute('INSERT INTO system_event_seen(event_uuid,observed_at) VALUES(?,?)',
                           (event_uuid, event['observed_at']))
                added += 1
            # Also support direct/legacy callers without an explicit collection cursor.
            cursors = dict(advances)
            for event in events:
                channel = event['channel']
                cursors[channel] = max(cursors.get(channel, 0), event['record_id'])
            for channel, record_id in cursors.items():
                if channel not in ('System', 'Application') or type(record_id) is not int or record_id < 0:
                    raise ValueError('Marcador de eventos inválido')
                db.execute('''INSERT INTO system_event_bookmarks(channel,record_id) VALUES(?,?)
                    ON CONFLICT(channel) DO UPDATE SET record_id=MAX(record_id,excluded.record_id)''',
                           (channel, record_id))
        return added

    def pending_system_events(self, limit: int = 30) -> list[dict]:
        with self._connection() as db:
            rows = db.execute('''SELECT payload FROM system_event_outbox
                WHERE retry_after IS NULL OR retry_after <= ?
                ORDER BY observed_at, event_uuid LIMIT ?''',
                (datetime.now(timezone.utc).isoformat(timespec='milliseconds'), max(1, min(limit, 30)))).fetchall()
        return [json.loads(row[0]) for row in rows]

    def defer_system_event(self, event_uuid: str, seconds: int = 600) -> None:
        until = (datetime.now(timezone.utc) + timedelta(seconds=seconds)).isoformat(timespec='milliseconds')
        with self._connection() as db:
            db.execute('UPDATE system_event_outbox SET retry_after=? WHERE event_uuid=?', (until, event_uuid))

    def deferred_system_event_count(self) -> int:
        with self._connection() as db:
            return int(db.execute('SELECT COUNT(*) FROM system_event_outbox WHERE retry_after IS NOT NULL').fetchone()[0])

    def acknowledge_system_events(self, uuids: list[str]) -> None:
        with self._connection() as db:
            db.executemany('DELETE FROM system_event_outbox WHERE event_uuid=?', ((u,) for u in uuids))
            cutoff = (datetime.now(timezone.utc) - timedelta(days=14)).isoformat()
            db.execute('DELETE FROM system_event_seen WHERE observed_at < ? AND event_uuid NOT IN '
                       '(SELECT event_uuid FROM system_event_outbox)', (cutoff,))

    def save_inventory(self, inventory: dict) -> None:
        if not isinstance(inventory.get("captured_at"), str):
            raise ValueError("Inventario sin fecha")
        raw = json.dumps(inventory, ensure_ascii=False)
        if len(raw.encode("utf-8")) > 120000:
            raise ValueError("Inventario demasiado grande")
        with self._connection() as db:
            db.execute("""INSERT OR REPLACE INTO inventory_outbox
                (slot, captured_at, payload) VALUES (1, ?, ?)""",
                (inventory["captured_at"], raw))

    def pending_inventory(self) -> dict | None:
        with self._connection() as db:
            row = db.execute("SELECT payload FROM inventory_outbox WHERE slot=1").fetchone()
            return json.loads(row[0]) if row else None

    def acknowledge_inventory(self, captured_at: str) -> None:
        with self._connection() as db:
            db.execute("DELETE FROM inventory_outbox WHERE slot=1 AND captured_at=?", (captured_at,))

    @staticmethod
    def _media_key(volume: dict) -> str:
        return '|'.join(str(volume.get(field) or '').upper() if field == 'device'
                        else str(volume.get(field) or '') for field in
                        ('device', 'mountpoint', 'media_class'))

    @staticmethod
    def _media_volume(device: str, media_class: str) -> dict:
        """Minimal metadata when a volume is ejected faster than WMI can query it."""
        return dict(device=device, mountpoint=device + "\\", media_class=media_class,
                    removable=True, serial=None, label=None, filesystem=None,
                    total_bytes=0, used_bytes=0, free_bytes=0)

    def _read_media_state(self, db) -> tuple[dict, bool]:
        row = db.execute('SELECT payload FROM media_state WHERE slot=1').fetchone()
        old = json.loads(row[0]) if row else {}
        # Migrate v5.1 and v5.3 keys without emitting fictitious disconnects.
        migrated = {self._media_key(v): v for v in old.values()}
        return migrated, row is None

    def _write_media_state(self, db, state: dict) -> None:
        db.execute('INSERT OR REPLACE INTO media_state(slot,payload) VALUES (1,?)',
                   (json.dumps(state, ensure_ascii=False),))

    def _record_media_event(self, db, kind: str, volume: dict, at: str) -> None:
        db.execute('INSERT INTO media_events (event_uuid,observed_at,kind,volume) VALUES (?,?,?,?)',
                   (str(uuid.uuid4()), at, kind, json.dumps(volume, ensure_ascii=False)))

    @classmethod
    def _enrich_pending_arrival(cls, db, key: str, volume: dict) -> None:
        """Enrich the latest unsent arrival, never an earlier drive occupant.

        WM_DEVICECHANGE knows only the drive letter. Win32_LogicalDisk may
        resolve its label/size seconds later. Keep the original timestamp and
        event UUID; do not invent a second insertion for metadata changes.
        """
        if not volume.get('label') and not volume.get('serial') and not volume.get('total_bytes'):
            return
        for sequence, kind, raw in db.execute(
            'SELECT sequence, kind, volume FROM media_events ORDER BY sequence DESC'
        ):
            old = json.loads(raw)
            if cls._media_key(old) != key:
                continue
            if kind == 'disconnected':
                return  # A later removal closes this drive's prior identity.
            if kind not in ('connected', 'detected'):
                continue
            if old.get('serial') and volume.get('serial') and old['serial'] != volume['serial']:
                return  # Different physical media; never rewrite historical evidence.
            enriched = old.copy()
            for field in ('label', 'serial', 'filesystem'):
                if volume.get(field):
                    enriched[field] = volume[field]
            if int(volume.get('total_bytes') or 0) > 0:
                for field in ('total_bytes', 'used_bytes', 'free_bytes'):
                    enriched[field] = volume.get(field, 0)
            if enriched != old:
                db.execute('UPDATE media_events SET volume=? WHERE sequence=?',
                           (json.dumps(enriched, ensure_ascii=False), sequence))
            return

    @classmethod
    def _recent_unresolved_arrival(cls, db, key: str) -> bool:
        """A just-mounted drive might not appear in Win32_LogicalDisk yet."""
        cutoff = datetime.now(timezone.utc) - timedelta(seconds=30)
        for kind, at, raw in db.execute(
            'SELECT kind, observed_at, volume FROM media_events ORDER BY sequence DESC'
        ):
            volume = json.loads(raw)
            if cls._media_key(volume) != key:
                continue
            if kind not in ('connected', 'detected') or int(volume.get('total_bytes') or 0) > 0:
                return False
            try:
                observed = datetime.fromisoformat(at.replace('Z', '+00:00'))
                if observed.tzinfo is None:
                    observed = observed.replace(tzinfo=timezone.utc)
                return observed > cutoff
            except ValueError:
                return False
        return False

    def record_media_state(self, volumes: list[dict], observed_at: str) -> int:
        """Polling reconciles Windows events. Unknown->known serial is enrichment,
        not a fabricated removal/insertion. Real disc swaps generate two events.
        """
        current = {self._media_key(v): v for v in volumes if v.get('removable') is True}
        if len(current) > 32:
            raise ValueError('Demasiadas unidades extraíbles')
        with self._connection() as db:
            previous, initial = self._read_media_state(db)
            # Do not invent an unplug while Windows is still mounting a newly
            # notified volume. The explicit WM removal path is never delayed.
            for key in previous.keys() - current.keys():
                if self._recent_unresolved_arrival(db, key):
                    current[key] = previous[key]
            changes = [('detected' if initial else 'connected', current[key])
                       for key in current.keys() - previous.keys()]
            changes.extend(('disconnected', previous[key])
                           for key in previous.keys() - current.keys())
            for key in current.keys() & previous.keys():
                original, recent = previous[key], current[key]
                if original.get('serial') and recent.get('serial') and original['serial'] != recent['serial']:
                    changes += [('disconnected', original), ('connected', recent)]
            if db.execute('SELECT COUNT(*) FROM media_events').fetchone()[0] + len(changes) > 1000:
                raise OSError('Cola local de eventos de medios llena; reintentar')
            for kind, volume in changes:
                self._record_media_event(db, kind, volume, observed_at)
            for key in current.keys() & previous.keys():
                original, updated = previous[key], current[key]
                if not (original.get('serial') and updated.get('serial')
                        and original['serial'] != updated['serial']):
                    self._enrich_pending_arrival(db, key, updated)
            self._write_media_state(db, current)
            return len(changes)

    def record_media_notification(self, kind: str, device: str, media_class: str,
                                  observed_at: str) -> bool:
        """Commit the timestamp received from WM_DEVICECHANGE before the next poll.
        A fast insert/removal still creates two events even if WMI misses the drive.
        """
        if kind not in ('connected', 'disconnected') or media_class not in ('usb', 'optical'):
            raise ValueError('Notificación de medio inválida')
        if len(device) != 2 or device[1] != ':' or not device[0].isalpha():
            raise ValueError('Unidad inválida')
        fallback = self._media_volume(device.upper(), media_class)
        key = self._media_key(fallback)
        with self._connection() as db:
            previous, _ = self._read_media_state(db)
            if db.execute('SELECT COUNT(*) FROM media_events').fetchone()[0] >= 1000:
                raise OSError('Cola local de eventos de medios llena')
            if kind == 'connected' and key in previous:
                return False
            if kind == 'connected':
                previous[key] = fallback
                volume = fallback
            else:
                volume = previous.pop(key, fallback)
            self._record_media_event(db, kind, volume, observed_at)
            self._write_media_state(db, previous)
            return True

    def pending_media_events(self, limit: int = 50, *, ready_only: bool = False) -> list[dict]:
        """For delivery, briefly hold arrival placeholders to await Windows metadata.

        Preserve sequence order. Offline/auditing callers can inspect all events
        with ready_only=False, and even unresolved arrivals eventually deliver.
        """
        with self._connection() as db:
            rows = list(db.execute(
                """SELECT event_uuid, observed_at, kind, volume FROM media_events
                WHERE retry_after IS NULL OR retry_after <= strftime('%Y-%m-%dT%H:%M:%f+00:00','now')
                ORDER BY sequence LIMIT ?""", (limit,)))
        events = []
        threshold = datetime.now(timezone.utc) - timedelta(seconds=20)
        for ident, at, kind, raw in rows:
            volume = json.loads(raw)
            if ready_only and kind in ('connected', 'detected') and (
                int(volume.get('total_bytes') or 0) <= 0 or not volume.get('label')
            ):
                try:
                    observed = datetime.fromisoformat(at.replace('Z', '+00:00'))
                    if observed.tzinfo is None:
                        observed = observed.replace(tzinfo=timezone.utc)
                    if observed > threshold:
                        break
                except ValueError:
                    pass  # Backend validates the original timestamp.
            events.append(dict(event_uuid=ident, observed_at=at, kind=kind, volume=volume))
        return events

    def defer_media_event(self, event_uuid: str, seconds: int = 600) -> None:
        until = (datetime.now(timezone.utc) + timedelta(seconds=seconds)).isoformat(timespec="milliseconds")
        with self._connection() as db:
            db.execute("UPDATE media_events SET retry_after=? WHERE event_uuid=?", (until, event_uuid))

    def acknowledge_media_events(self, ids: list[str]) -> None:
        with self._connection() as db:
            db.executemany("DELETE FROM media_events WHERE event_uuid=?", ((ident,) for ident in ids))

    def record_usage(self, boot_id: str, counters: dict, at: datetime | None = None) -> int:
        """Atomically save baselines and hourly increments, even when offline."""
        from network_usage import split_utc_hours, bucket_uuid
        at = (at or datetime.now(timezone.utc)).astimezone(timezone.utc)
        if not boot_id or len(boot_id) > 64:
            raise ValueError('Invalid boot identity')
        stored_at = at.isoformat()
        changed = 0
        with self._connection() as db:
            for name, counts in counters.items():
                if not name or len(name) > 120:
                    continue
                rx, tx = counts
                if type(rx) is not int or type(tx) is not int or min(rx, tx) < 0:
                    continue
                row = db.execute('SELECT boot_id,rx_counter,tx_counter,observed_at FROM usage_baselines WHERE interface_name=?', (name,)).fetchone()
                if row and row[0] == boot_id and rx >= row[1] and tx >= row[2]:
                    old = datetime.fromisoformat(row[3])
                    elapsed = (at - old).total_seconds()
                    if 0 < elapsed <= 7200:
                        for hour, rx_part, tx_part, seconds in split_utc_hours(old, at, rx-row[1], tx-row[2]):
                            key = bucket_uuid(boot_id, name, hour)
                            present = db.execute('SELECT 1 FROM usage_hours WHERE bucket_uuid=?', (key,)).fetchone()
                            if not present and db.execute('SELECT COUNT(*) FROM usage_hours').fetchone()[0] >= 50000:
                                raise OSError('Cola de consumo alcanzó 50.000 horas-interfaz; no se eliminan muestras pendientes')
                            db.execute('''INSERT INTO usage_hours(bucket_uuid,interface_name,boot_id,hour_utc,rx_bytes,tx_bytes,observed_seconds,revision)
                                VALUES (?,?,?,?,?,?,?,1)
                                ON CONFLICT(bucket_uuid) DO UPDATE SET
                                rx_bytes=rx_bytes+excluded.rx_bytes,
                                tx_bytes=tx_bytes+excluded.tx_bytes,
                                observed_seconds=min(3600,observed_seconds+excluded.observed_seconds),
                                revision=revision+1, retry_after=NULL''',
                                (key, name, boot_id, hour.isoformat(), rx_part, tx_part, seconds))
                            changed += 1
                db.execute('''INSERT INTO usage_baselines(interface_name,boot_id,rx_counter,tx_counter,observed_at)
                    VALUES (?,?,?,?,?) ON CONFLICT(interface_name) DO UPDATE SET
                    boot_id=excluded.boot_id, rx_counter=excluded.rx_counter,
                    tx_counter=excluded.tx_counter, observed_at=excluded.observed_at''',
                    (name, boot_id, rx, tx, stored_at))
            # Never purge unsent data or an hour that can still change.
            cutoff = (at - timedelta(hours=3)).isoformat()
            db.execute('DELETE FROM usage_hours WHERE hour_utc < ? AND revision = synced_revision', (cutoff,))
        return changed

    def pending_usage(self, limit: int = 32) -> list[dict]:
        with self._connection() as db:
            rows = db.execute('''SELECT bucket_uuid,interface_name,boot_id,hour_utc,rx_bytes,tx_bytes,observed_seconds,revision
                FROM usage_hours WHERE revision > synced_revision AND
                    (retry_after IS NULL OR retry_after <= ?) ORDER BY hour_utc, bucket_uuid LIMIT ?''',
                (datetime.now(timezone.utc).isoformat(), max(1, min(64, limit)))).fetchall()
            return [dict(zip(('bucket_uuid','interface_name','boot_id','hour_utc','rx_bytes','tx_bytes','observed_seconds','revision'), row)) for row in rows]

    def defer_usage(self, bucket_uuid: str, seconds: int = 600) -> None:
        until = (datetime.now(timezone.utc) + timedelta(seconds=seconds)).isoformat()
        with self._connection() as db:
            db.execute('UPDATE usage_hours SET retry_after=? WHERE bucket_uuid=?', (until, bucket_uuid))

    def acknowledge_usage(self, acknowledged: list[dict]) -> None:
        with self._connection() as db:
            for item in acknowledged:
                db.execute('''UPDATE usage_hours SET synced_revision=?
                    WHERE bucket_uuid=? AND revision=? AND synced_revision < ?''',
                    (item['revision'], item['bucket_uuid'], item['revision'], item['revision']))

    def usage_pending_count(self) -> int:
        with self._connection() as db:
            return int(db.execute('SELECT COUNT(*) FROM usage_hours WHERE revision > synced_revision').fetchone()[0])

    def add_sample(self, sample: dict) -> str:
        unique = str(uuid.uuid4())
        item = {**sample, "sample_uuid": unique,
                "captured_at": datetime.now(timezone.utc).isoformat(timespec="milliseconds")}
        with self._connection() as db:
            if self.path.exists() and self.path.stat().st_size > MAX_STORAGE_BYTES:
                free = db.execute("PRAGMA freelist_count").fetchone()[0]
                page = db.execute("PRAGMA page_size").fetchone()[0]
                if free * page < 1024 * 1024:
                    raise OSError("La cola local supera 256 MiB sin páginas reutilizables; conservamos el historial")
            count = db.execute("SELECT COUNT(*) FROM samples").fetchone()[0]
            if count >= MAX_QUEUED_SAMPLES:
                raise OSError("La cola local alcanzó su límite de 20.000 muestras; no se descartarán silenciosamente")
            db.execute("INSERT INTO samples (sample_uuid, captured_at, payload) VALUES (?,?,?)",
                       (unique, item["captured_at"], json.dumps(item, ensure_ascii=False)))
        return unique

    def pending_samples(self, limit: int = 100, max_bytes: int = 200000) -> list[dict]:
        selected = []
        size = 16
        with self._connection() as db:
            for (raw,) in db.execute("""SELECT payload FROM samples
                    WHERE retry_after IS NULL OR retry_after <= strftime('%Y-%m-%dT%H:%M:%f+00:00','now')
                    ORDER BY sequence LIMIT ?""", (limit,)):
                added = len(raw.encode("utf-8")) + 2
                if size + added > max_bytes:
                    # Even one sample might exceed request size. Drop no data silently.
                    if not selected:
                        raise ValueError("Una muestra excede el tamaño máximo; revisá el inventario de procesos.")
                    break
                selected.append(json.loads(raw))
                size += added
        return selected

    def defer_sample(self, sample_uuid: str, seconds: int = 600) -> None:
        until = (datetime.now(timezone.utc) + timedelta(seconds=seconds)).isoformat(timespec="milliseconds")
        with self._connection() as db:
            db.execute("UPDATE samples SET retry_after=? WHERE sample_uuid=?", (until, sample_uuid))

    def retry_deferred_samples(self) -> int:
        with self._connection() as db:
            count = db.execute("UPDATE samples SET retry_after=NULL WHERE retry_after IS NOT NULL").rowcount
            count += db.execute("UPDATE media_events SET retry_after=NULL WHERE retry_after IS NOT NULL").rowcount
            count += db.execute("UPDATE usage_hours SET retry_after=NULL WHERE retry_after IS NOT NULL").rowcount
            count += db.execute("UPDATE system_event_outbox SET retry_after=NULL WHERE retry_after IS NOT NULL").rowcount
            return count

    def acknowledge_samples(self, ids: list[str]) -> None:
        if not ids:
            return
        with self._connection() as db:
            db.executemany("DELETE FROM samples WHERE sample_uuid=?", ((id_,) for id_ in ids))

    def sample_count(self) -> int:
        with self._connection() as db:
            return int(db.execute("SELECT COUNT(*) FROM samples").fetchone()[0])

    def has_command(self, command_uuid: str) -> bool:
        with self._connection() as db:
            return db.execute("SELECT 1 FROM command_receipts WHERE command_uuid=?", (command_uuid,)).fetchone() is not None

    def begin_command(self, command_uuid: str) -> bool:
        # Unknown is written *before* execution. After a crash it will not run twice.
        with self._connection() as db:
            result = db.execute("""INSERT OR IGNORE INTO command_receipts
                (command_uuid, status, message, created_at)
                VALUES (?, 'unknown', 'Resultado no verificable tras la interrupción del agente.', ?)""",
                (command_uuid, datetime.now(timezone.utc).isoformat()))
            return result.rowcount == 1

    def mark_awaiting_user(self, command_uuid: str) -> None:
        with self._connection() as db:
            db.execute("""UPDATE command_receipts SET status='awaiting_user',
                message='Ventana solicitada; esperando botón Cerrar.'
                WHERE command_uuid=? AND acknowledged=0""", (command_uuid,))

    def finish_command(self, command_uuid: str, status: str, message: str):
        if status not in ("completed", "failed", "unknown"):
            raise ValueError("Estado inválido")
        with self._connection() as db:
            db.execute("UPDATE command_receipts SET status=?, message=? WHERE command_uuid=?",
                       (status, message[:500], command_uuid))

    def pending_results(self) -> list[dict]:
        with self._connection() as db:
            return [dict(id=ident, result={"status": status, "message": msg})
                    for ident, status, msg in db.execute("""SELECT command_uuid, status, message
                    FROM command_receipts WHERE acknowledged=0 AND status != 'awaiting_user' ORDER BY created_at LIMIT 10""")]

    def acknowledge_result(self, command_uuid: str):
        with self._connection() as db:
            db.execute("UPDATE command_receipts SET acknowledged=1 WHERE command_uuid=?", (command_uuid,))

    def purge_acked_commands(self, keep_days: int = 90):
        # Keep a replay protection window, not merely unsent results.
        cutoff = (datetime.now(timezone.utc) - timedelta(days=keep_days)).isoformat()
        with self._connection() as db:
            db.execute("DELETE FROM command_receipts WHERE acknowledged=1 AND created_at < ?", (cutoff,))

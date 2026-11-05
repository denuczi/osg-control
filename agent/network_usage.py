"""Network usage from cumulative NIC counters, with durable hourly snapshots.

Measures NIC traffic (LAN + Internet), never ISP-billed Internet consumption.
No packet capture, DNS history or speed tests are performed.
"""
from __future__ import annotations

from datetime import datetime, timedelta, timezone
import os
import re
import uuid

import psutil

# Virtual/tunnel interfaces may mirror physical traffic, causing double counts.
_EXCLUDED = re.compile(r'loopback|vethernet|virtual|vmware|virtualbox|hyper-v|docker|veth|tap-|tun|wireguard|tailscale|zerotier|npcap|isatap|teredo|pseudo|bluetooth', re.I)


def eligible_interface_names() -> set[str]:
    """Use the same physical-interface policy for speed and accumulated bytes."""
    configured = {s.strip().casefold() for s in os.environ.get('NODO_USAGE_INTERFACES', '').split(',') if s.strip()}
    stats = psutil.net_if_stats()
    addresses = psutil.net_if_addrs()
    names = set()
    for name, nic in stats.items():
        if len(name) > 120 or not nic.isup:
            continue
        if configured:
            if name.casefold() not in configured:
                continue
        elif _EXCLUDED.search(name):
            continue
        if not configured:
            has_mac = any(a.family == psutil.AF_LINK and a.address and
                          a.address.lower() not in ('00:00:00:00:00:00', '00-00-00-00-00-00')
                          for a in addresses.get(name, []))
            if not has_mac:
                continue
        names.add(name)
    return names


def read_counters() -> tuple[str, dict[str, tuple[int, int]]]:
    """Return (boot identity, cumulative RX/TX per eligible interface)."""
    names = eligible_interface_names()
    counters = psutil.net_io_counters(pernic=True, nowrap=False) or {}
    found = {}
    for name in names:
        item = counters.get(name)
        if not item or item.bytes_recv < 0 or item.bytes_sent < 0:
            continue
        found[name] = (int(item.bytes_recv), int(item.bytes_sent))
    return str(int(psutil.boot_time())), found


def split_utc_hours(start: datetime, end: datetime, rx: int, tx: int):
    """Allocate observed bytes across UTC hour boundaries, preserving exact totals.

    Attribution within the interval is time-weighted; the underlying interval's
    RX/TX *totals* remain exact counter differences. Return bounded UTC buckets.
    """
    seconds = (end - start).total_seconds()
    if seconds <= 0 or seconds > 7200:
        return []
    slots = []
    cursor = start
    while cursor < end:
        hour = cursor.replace(minute=0, second=0, microsecond=0)
        until = min(end, hour + timedelta(hours=1))
        slots.append((hour, (until - cursor).total_seconds()))
        cursor = until
    assigned_rx = assigned_tx = 0
    for idx, (hour, duration) in enumerate(slots):
        last = idx == len(slots) - 1
        part_rx = rx - assigned_rx if last else int(rx * (duration / seconds))
        part_tx = tx - assigned_tx if last else int(tx * (duration / seconds))
        assigned_rx += part_rx
        assigned_tx += part_tx
        yield hour, part_rx, part_tx, round(duration)


def bucket_uuid(boot_id: str, interface: str, hour: datetime) -> str:
    return str(uuid.uuid5(uuid.NAMESPACE_URL, f'nodo-network-v1:{boot_id}:{interface}:{hour.isoformat()}'))

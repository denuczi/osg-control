"""Inventario técnico autorizado; nunca enumera archivos del usuario ni historiales de navegación.

Los atributos no disponibles se envían como null. El inventario es distinto de la
telemetría de alta frecuencia y no debe bloquear la captura de métricas.
"""
from __future__ import annotations

import ipaddress
import json
import os
import platform
import socket
import subprocess
from datetime import datetime, timezone

import psutil


def _windows_query(script: str, timeout: int = 12):
    """Consultas PowerShell constantes, sin datos del usuario en la línea de comandos."""
    if os.name != "nt":
        return None
    try:
        # Windows PowerShell 5.1 otherwise uses a legacy console code page for
        # stdout. Decoding that as UTF-8 corrupts Windows event messages.
        utf8_script = (
            '[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false); '
            '$OutputEncoding = [Console]::OutputEncoding; ' + script
        )
        process = subprocess.run(
            ["powershell.exe", "-NoProfile", "-NonInteractive", "-Command", utf8_script],
            capture_output=True, text=True, encoding="utf-8", errors="strict", timeout=timeout,
            check=False, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
        )
        if process.returncode != 0 or not process.stdout.strip():
            return None
        return json.loads(process.stdout.strip().lstrip("\ufeff"))
    except (OSError, subprocess.TimeoutExpired, ValueError, json.JSONDecodeError):
        return None


def _records(value):
    if isinstance(value, list):
        return [x for x in value if isinstance(x, dict)]
    return [value] if isinstance(value, dict) else []


def processor_info() -> dict:
    physical = psutil.cpu_count(logical=False)
    logical = psutil.cpu_count(logical=True)
    freq = psutil.cpu_freq()
    result = {
        "name": platform.processor()[:160] or None,
        "physical_cores": physical,
        "logical_processors": logical,
        "max_mhz": round(freq.max, 1) if freq and freq.max > 0 else None,
        "current_mhz": round(freq.current, 1) if freq and freq.current > 0 else None,
    }
    windows = _windows_query("Get-CimInstance Win32_Processor | Select-Object -First 1 Name,NumberOfCores,NumberOfLogicalProcessors,MaxClockSpeed,CurrentClockSpeed | ConvertTo-Json -Compress")
    if isinstance(windows, dict):
        result["name"] = (str(windows.get("Name") or result["name"] or "")[:160] or None)
        for key, source in (("physical_cores", "NumberOfCores"), ("logical_processors", "NumberOfLogicalProcessors"), ("max_mhz", "MaxClockSpeed"), ("current_mhz", "CurrentClockSpeed")):
            if isinstance(windows.get(source), (int, float)) and windows[source] > 0:
                result[key] = windows[source]
    return result


def storage_info() -> list[dict]:
    """Inventario por volumen. Tipo físico solo si Windows facilita un mapeo fiable."""
    disks = []
    windows = _windows_query("Get-Volume | Where-Object { $_.DriveLetter -ne $null -and $_.DriveType -ne 'CD-ROM' } | Select-Object DriveLetter,FileSystemLabel,FileSystem,DriveType,Size,SizeRemaining | ConvertTo-Json -Compress")
    win_by_letter = {str(d.get("DriveLetter", "")).upper(): d for d in _records(windows)}
    seen = set()
    for part in psutil.disk_partitions(all=False):
        mount = part.mountpoint
        if not mount or mount.casefold() in seen:
            continue
        seen.add(mount.casefold())
        try:
            usage = psutil.disk_usage(mount)
        except (psutil.Error, OSError):
            continue
        letter = mount[0].upper() if os.name == "nt" and len(mount) >= 2 and mount[1] == ":" else None
        win = win_by_letter.get(letter, {})
        removable = str(win.get("DriveType", "")).lower() == "removable" or "removable" in part.opts.lower()
        disks.append({
            "mountpoint": mount[:120], "device": part.device[:160],
            "filesystem": (str(win.get("FileSystem") or part.fstype)[:40] or None),
            "label": (str(win.get("FileSystemLabel") or "")[:100] or None),
            "total_bytes": usage.total, "used_bytes": usage.used, "free_bytes": usage.free,
            "used_percent": usage.percent, "removable": removable,
            # No inferir tipo de medio a partir del bus ni de la letra.
            "media_type": None,
        })
    # Windows Storage cmdlets: asociar por letra mediante partición -> disco.
    physical = _windows_query(
        "Get-Partition | Where-Object DriveLetter | ForEach-Object { "
        "$p=$_; $d=Get-Disk -Number $p.DiskNumber -ErrorAction SilentlyContinue; "
        "$matches=@(Get-PhysicalDisk -ErrorAction SilentlyContinue | "
        "Where-Object { $_.FriendlyName -eq $d.FriendlyName -and $_.Size -eq $d.Size }); "
        "$media=if($matches.Count -eq 1){ [string]$matches[0].MediaType }else{''}; "
        "[PSCustomObject]@{Letter=[string]$p.DriveLetter;BusType=[string]$d.BusType;"
        "MediaType=$media;FriendlyName=[string]$d.FriendlyName} "
        "} | ConvertTo-Json -Compress"
    )
    by_letter = {str(x.get("Letter", "")).upper(): x for x in _records(physical)}
    for item in disks:
        letter = item["mountpoint"][:1].upper()
        disk = by_letter.get(letter) if os.name == "nt" else None
        if disk:
            item["bus_type"] = str(disk.get("BusType") or "")[:30] or None
            item["model"] = str(disk.get("FriendlyName") or "")[:120] or None
            media = str(disk.get("MediaType") or "").lower()
            if media in ("ssd", "hdd", "scm"):
                item["media_type"] = media.upper()
            if str(disk.get("BusType", "")).upper() == "USB":
                item["removable"] = True
    return disks[:32]


def _network_addresses() -> list[dict]:
    result = []
    stats = psutil.net_if_stats()
    for name, addresses in psutil.net_if_addrs().items():
        ipv4, ipv6, mac = [], [], None
        for addr in addresses:
            if addr.family == socket.AF_INET:
                subnet = None
                try:
                    if addr.netmask:
                        subnet = str(ipaddress.ip_network(f"{addr.address}/{addr.netmask}", strict=False))
                except ValueError:
                    pass
                ipv4.append({"address": addr.address, "netmask": addr.netmask, "subnet": subnet})
            elif addr.family == socket.AF_INET6:
                ipv6.append(addr.address.split("%")[0])
            elif addr.family == getattr(psutil, "AF_LINK", object()):
                mac = addr.address or None
        state = stats.get(name)
        result.append({"name": name[:120], "up": bool(state and state.isup),
                       "link_speed_mbps": state.speed if state and state.speed > 0 else None,
                       "ipv4": ipv4[:8], "ipv6": ipv6[:8], "mac": mac[:32] if mac else None})
    return result[:32]


def network_info() -> dict:
    interfaces = _network_addresses()
    gateway = None
    dns = []
    ssid = None
    neighbors = []
    profiles = []
    # An ARP entry is not a discovered device when it matches our own IP or
    # an interface's directed broadcast address.
    local_ips = set()
    broadcast_ips = set()
    for adapter in interfaces:
        for address in adapter.get('ipv4', []):
            if address.get('address'):
                local_ips.add(address['address'])
            try:
                if address.get('subnet'):
                    network = ipaddress.ip_network(address['subnet'], strict=False)
                    broadcast_ips.add(str(network.broadcast_address))
            except ValueError:
                pass
    if os.name == "nt":
        # The active default route is authoritative; do not guess from the
        # first configured adapter (which may be disconnected or a VPN).
        info = _windows_query(
            "$interfaces=@{}; Get-NetIPInterface -AddressFamily IPv4 -ErrorAction SilentlyContinue | "
            "ForEach-Object {$interfaces[[string]$_.InterfaceIndex]=[int]$_.InterfaceMetric}; "
            "Get-NetRoute -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue | "
            "Where-Object {$_.NextHop -ne '0.0.0.0' -and $_.State -eq 'Alive'} | "
            "Sort-Object @{Expression={ [int]$_.RouteMetric + [int]$interfaces[[string]$_.InterfaceIndex] }} | "
            "Select-Object -First 1 -ExpandProperty NextHop | ConvertTo-Json -Compress"
        )
        if isinstance(info, str):
            try:
                gateway = str(ipaddress.ip_address(info))
            except ValueError:
                pass
        servers = _windows_query("Get-DnsClientServerAddress -AddressFamily IPv4 | Select-Object -ExpandProperty ServerAddresses | Select-Object -Unique | ConvertTo-Json -Compress")
        candidates = servers if isinstance(servers, list) else ([servers] if isinstance(servers, str) else [])
        for candidate in candidates:
            if isinstance(candidate, str):
                try:
                    dns.append(str(ipaddress.ip_address(candidate)))
                except ValueError:
                    continue
        raw_profiles = _windows_query("Get-NetConnectionProfile -ErrorAction SilentlyContinue | Select-Object InterfaceAlias,Name,NetworkCategory,IPv4Connectivity | ConvertTo-Json -Compress")
        for profile in _records(raw_profiles):
            profiles.append({"interface": str(profile.get("InterfaceAlias") or "")[:120],
                             "name": str(profile.get("Name") or "")[:100],
                             "category": str(profile.get("NetworkCategory") or "")[:30],
                             "ipv4_connectivity": str(profile.get("IPv4Connectivity") or "")[:40]})
        peers = _windows_query("Get-NetNeighbor -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.State -notin @('Unreachable','Incomplete') -and $_.LinkLayerAddress } | Select-Object IPAddress,LinkLayerAddress,State -First 256 | ConvertTo-Json -Compress")
        for peer in _records(peers):
            try:
                ip = str(ipaddress.ip_address(str(peer.get("IPAddress", ""))))
            except ValueError:
                continue
            from diagnostics_v6 import valid_neighbor
            mac = str(peer.get("LinkLayerAddress") or "")[:32]
            if not valid_neighbor(ip, mac) or ip in local_ips or ip in broadcast_ips:
                continue
            neighbors.append({"ip": ip, "mac": mac,
                              "state": str(peer.get("State") or "")[:30]})
            if len(neighbors) >= 64:
                break
        # Los vecinos observados NO constituyen un escaneo completo de toda la LAN.
        # SSID opcional: no se infiere de IP o gateway.
    return {"interfaces": interfaces, "default_gateway": gateway, "dns_servers": dns[:12], "ssid": ssid, "neighbors": neighbors, "profiles": profiles[:16]}


def collect_inventory() -> dict:
    return {
        "captured_at": datetime.now(timezone.utc).isoformat(timespec="milliseconds"),
        "processor": processor_info(),
        "volumes": storage_info(),
        "network": network_info(),
    }


def inserted_media_info() -> list[dict] | None:
    """Observe removable drives, optical media and mounted USB fixed disks.

    Passive local WMI/Storage queries. Does not read any personal files.
    """
    if os.name != 'nt':
        return []
    raw = _windows_query(
        "$items = @(Get-CimInstance Win32_LogicalDisk -ErrorAction Stop | "
        "Where-Object { $_.DriveType -in @(2,3,5) } | "
        "Select-Object DeviceID,VolumeName,VolumeSerialNumber,FileSystem,Size,FreeSpace,DriveType); "
        "$usb = @{}; Get-Partition -ErrorAction SilentlyContinue | Where-Object DriveLetter | "
        "ForEach-Object { $p=$_; $disk=Get-Disk -Number $p.DiskNumber -ErrorAction SilentlyContinue; "
        "if($disk -and [string]$disk.BusType -eq 'USB'){$usb[[string]$p.DriveLetter]=$true} }; "
        "$items | ForEach-Object { $row=$_; $letter=[string]$row.DeviceID; "
        "[PSCustomObject]@{DeviceID=$row.DeviceID;VolumeName=$row.VolumeName; "
        "VolumeSerialNumber=$row.VolumeSerialNumber;FileSystem=$row.FileSystem; "
        "Size=$row.Size;FreeSpace=$row.FreeSpace;DriveType=$row.DriveType; "
        "UsbDisk=($usb.ContainsKey($letter.Substring(0,1)))} } | ConvertTo-Json -Compress", timeout=12)
    if raw is None:
        return None  # Failed query: never manufacture a removal.
    result = []
    for item in _records(raw):
        try:
            drive_type = int(item.get('DriveType') or 0)
            media_class = 'optical' if drive_type == 5 else 'usb'
            if drive_type == 3 and item.get('UsbDisk') is not True:
                continue
            total = max(0, int(item.get('Size') or 0))
            free = max(0, int(item.get('FreeSpace') or 0))
            if media_class == 'optical' and total == 0 and not item.get('VolumeName'):
                continue
            mount = str(item.get('DeviceID') or '')[:2].upper()
            if len(mount) != 2 or mount[1] != ':':
                continue
            result.append({
                'device': mount, 'mountpoint': mount + '\\',
                'label': str(item.get('VolumeName') or '')[:100] or None,
                'serial': str(item.get('VolumeSerialNumber') or '')[:100] or None,
                'filesystem': str(item.get('FileSystem') or '')[:40] or None,
                'total_bytes': total, 'used_bytes': max(0, total - free), 'free_bytes': free,
                'removable': True, 'media_class': media_class,
            })
        except (ValueError, TypeError):
            continue
    return result[:32]


class NetworkMeter:
    """Calcula tasa de transferencia por diferencias monotónicas; no es un speedtest."""
    def __init__(self, clock=None, counters=None):
        import time
        self._clock = clock or time.monotonic
        self._counters = counters or (lambda: psutil.net_io_counters(pernic=True))
        self._previous = None

    def sample(self) -> dict:
        now = self._clock()
        counters = self._counters()
        previous = self._previous
        self._previous = (now, counters)
        rates = []
        stats = psutil.net_if_stats()
        for name, counter in counters.items():
            if name not in stats or not stats[name].isup:
                continue
            sent = received = None
            if previous and now > previous[0] and name in previous[1]:
                old = previous[1][name]
                dt = now - previous[0]
                if counter.bytes_sent >= old.bytes_sent and counter.bytes_recv >= old.bytes_recv:
                    sent = round((counter.bytes_sent - old.bytes_sent) * 8 / dt)
                    received = round((counter.bytes_recv - old.bytes_recv) * 8 / dt)
            rates.append({"name": name[:120], "tx_bps": sent, "rx_bps": received})
        return {"interfaces": rates[:32]}

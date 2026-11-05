"""V6 diagnostic collectors, isolated from remote control and high-rate telemetry.

Uses built-in Windows CIM/PowerShell read-only queries. A failed sensor is an
explicit status, never a fabricated healthy reading. No personal file inspection.
"""
from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timezone
import logging
import ipaddress
import os
from typing import Callable

from inventory import _windows_query, _records
import diagnostics_normalize as normalize


def _section(collector: Callable[[], dict | None]) -> dict:
    if os.name != 'nt':
        return {'status': 'unsupported', 'data': None}
    try:
        value = collector()
        if not isinstance(value, dict):
            return {'status': 'unavailable', 'data': None}
        return {'status': 'ok', 'data': value}
    except (OSError, ValueError, TypeError, KeyError, OverflowError):
        return {'status': 'error', 'data': None}


def hardware() -> dict | None:
    return _windows_query(r'''
$ErrorActionPreference='Stop'
$system=Get-CimInstance Win32_ComputerSystem -ErrorAction Stop | Select-Object -First 1
$bios=Get-CimInstance Win32_BIOS -ErrorAction SilentlyContinue | Select-Object -First 1
$board=Get-CimInstance Win32_BaseBoard -ErrorAction SilentlyContinue | Select-Object -First 1
$ram=@(Get-CimInstance Win32_PhysicalMemory -ErrorAction SilentlyContinue | ForEach-Object {
  [PSCustomObject]@{CapacityBytes=$_.Capacity;SpeedMHz=$_.ConfiguredClockSpeed;Manufacturer=$_.Manufacturer;PartNumber=$_.PartNumber;Slot=$_.DeviceLocator}
})
$array=Get-CimInstance Win32_PhysicalMemoryArray -ErrorAction SilentlyContinue | Select-Object -First 1
$batt=@(Get-CimInstance Win32_Battery -ErrorAction SilentlyContinue)
$charge=if($batt.Count -gt 0){$batt[0].EstimatedChargeRemaining}else{$null}
$batStatus=if($batt.Count -gt 0){[string]$batt[0].BatteryStatus}else{$null}
$design=$null; $full=$null; $cycles=$null
try {$b=Get-CimInstance -Namespace root/wmi -ClassName BatteryStaticData -ErrorAction Stop | Select-Object -First 1; if($b){$design=$b.DesignedCapacity}} catch {}
try {$b=Get-CimInstance -Namespace root/wmi -ClassName BatteryFullChargedCapacity -ErrorAction Stop | Select-Object -First 1; if($b){$full=$b.FullChargedCapacity}} catch {}
try {$b=Get-CimInstance -Namespace root/wmi -ClassName BatteryCycleCount -ErrorAction Stop | Select-Object -First 1; if($b){$cycles=$b.CycleCount}} catch {}
[PSCustomObject]@{
 Manufacturer=[string]$system.Manufacturer;Model=[string]$system.Model;
 BiosVersion=if($bios){[string]$bios.SMBIOSBIOSVersion}else{$null};
 Serial=if($bios){[string]$bios.SerialNumber}else{$null};
 Board=if($board){[string]$board.Product}else{$null};
 MemorySlots=if($array){$array.MemoryDevices}else{$null};
 MemoryModules=$ram;
 BatteryPresent=($batt.Count -gt 0);BatteryPercent=$charge;BatteryStatus=$batStatus;
 BatteryDesignCapacity=$design;BatteryFullChargeCapacity=$full;BatteryCycles=$cycles
} | ConvertTo-Json -Depth 5 -Compress
''', timeout=25)


def disks() -> dict | None:
    raw = _windows_query(r'''
$ErrorActionPreference='Stop'
@(Get-PhysicalDisk -ErrorAction Stop | Select-Object -First 24 | ForEach-Object {
  $d=$_
  $r=$null
  try {$r=Get-StorageReliabilityCounter -PhysicalDisk $d -ErrorAction Stop} catch {}
  [PSCustomObject]@{Name=[string]$d.FriendlyName;DeviceId=[string]$d.DeviceId;
    MediaType=[string]$d.MediaType;Health=[string]$d.HealthStatus;
    Temperature=if($r){$r.Temperature}else{$null};
    Wear=if($r){$r.Wear}else{$null};
    PowerOnHours=if($r){$r.PowerOnHours}else{$null};
    ReadErrors=if($r){$r.ReadErrorsUncorrected}else{$null};
    WriteErrors=if($r){$r.WriteErrorsUncorrected}else{$null}}
}) | ConvertTo-Json -Depth 4 -Compress
''', timeout=30)
    if raw is None:
        return None
    return {'drives': _records(raw)[:24]}


def windows_version() -> dict | None:
    """Read Windows release independently from potentially slow license WMI.

    A failure to inspect commercial licenses must not hide the OS build. ESU
    enrollment for consumer devices cannot be reliably inferred from WMI.
    """
    version = _windows_query(r'''
$ErrorActionPreference = 'Stop'
$os = Get-CimInstance -ClassName Win32_OperatingSystem -ErrorAction Stop | Select-Object -First 1
$registry = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion' -ErrorAction SilentlyContinue
[PSCustomObject]@{
 Caption=if($os){[string]$os.Caption}else{$null}
 EditionId=if($registry){[string]$registry.EditionID}else{$null}
 DisplayVersion=if($registry){[string]$registry.DisplayVersion}else{$null}
 Build=if($os){[int]$os.BuildNumber}else{$null}
 UBR=if($registry -and $null -ne $registry.UBR){[int]$registry.UBR}else{$null}
} | ConvertTo-Json -Depth 3 -Compress
''', timeout=12)
    if not isinstance(version, dict):
        return None
    build = version.get('Build')
    if type(build) is not int or not 10240 <= build < 22000:
        version.update(EsuCommercialStatus='not_applicable', EsuLicensedYears=[])
        return version
    # Limited WMI query, read only; a timeout is an unknown result, not "no ESU".
    licenses = _windows_query(r'''
$ErrorActionPreference = 'Stop'
$ids = @{
 'f520e45e-7413-4a34-a497-d2765967d094'=1
 '1043add5-23b1-4afb-9a0f-64343c8f3f8d'=2
 '83d49986-add3-41d7-ba33-87c7bfb5c0fb'=3
}
$years=@()
$products = @(Get-CimInstance Win32_SoftwareLicensingProduct -Filter 'LicenseStatus = 1' -ErrorAction Stop)
foreach($product in $products){
 $key=([string]$product.ID).ToLowerInvariant()
 if($ids.ContainsKey($key)){ $years += [int]$ids[$key] }
}
[PSCustomObject]@{Status=if($years.Count -gt 0){'licensed'}else{'not_detected'};Years=@($years | Sort-Object -Unique)} | ConvertTo-Json -Depth 3 -Compress
''', timeout=12)
    if isinstance(licenses, dict) and licenses.get('Status') in ('licensed', 'not_detected'):
        version['EsuCommercialStatus'] = licenses['Status']
        version['EsuLicensedYears'] = licenses.get('Years') if isinstance(licenses.get('Years'), list) else []
    else:
        version['EsuCommercialStatus'] = 'unknown'
        version['EsuLicensedYears'] = []
    return version


def security() -> dict | None:
    raw = _windows_query(r'''
$ErrorActionPreference='Continue'
$defender=$null; $fw=$null; $providers=@(); $pending=$null
try {$defender=Get-MpComputerStatus -ErrorAction Stop} catch {}
try {$fw=@(Get-NetFirewallProfile -PolicyStore ActiveStore -ErrorAction Stop | ForEach-Object {
 [PSCustomObject]@{Name=[string]$_.Name;Enabled=[bool]$_.Enabled}
})} catch {}
try {$providers=@(Get-CimInstance -Namespace root/SecurityCenter2 -ClassName AntivirusProduct -ErrorAction Stop |
 Select-Object -First 12 | ForEach-Object {[string]$_.displayName})} catch {}
# Local cached search: never contact Windows Update or download patches.
try {
 $session=New-Object -ComObject Microsoft.Update.Session
 $searcher=$session.CreateUpdateSearcher(); $searcher.Online=$false
 $pending=$searcher.Search("IsInstalled=0 and IsHidden=0 and Type='Software'").Updates.Count
} catch {}
[PSCustomObject]@{
 DefenderMode=if($defender){[string]$defender.AMRunningMode}else{$null};
 DefenderRealtime=if($defender -and $null -ne $defender.RealTimeProtectionEnabled){[bool]$defender.RealTimeProtectionEnabled}else{$null};
 DefenderSignatureAge=if($defender){$defender.AntivirusSignatureAge}else{$null};
 DefenderSignatureUpdated=if($defender -and $defender.AntivirusSignatureLastUpdated){$defender.AntivirusSignatureLastUpdated.ToString('o')}else{$null};
 FirewallProfiles=$fw;AntivirusProviders=$providers;WindowsUpdatesPending=$pending;
} | ConvertTo-Json -Depth 5 -Compress
''', timeout=25)
    version = windows_version()
    # The OS identity query runs separately from protection. Its success must
    # not be misrepresented as a successful Defender / firewall capture.
    if not isinstance(raw, dict):
        return {'Windows': version, 'ProtectionQueryStatus': 'unavailable'} if isinstance(version, dict) else None
    observed = (
        raw.get('DefenderMode') not in (None, '')
        or type(raw.get('DefenderRealtime')) is bool
        or bool(raw.get('AntivirusProviders'))
        or bool(raw.get('FirewallProfiles'))
        or raw.get('WindowsUpdatesPending') is not None
    )
    raw['ProtectionQueryStatus'] = 'ok' if observed else 'unavailable'
    raw['Windows'] = version
    return raw


# IDs are not globally unique: use both log and provider to classify events.
# The list is deliberately conservative. Unknown vendors are not assigned a
# misleading description; additional providers require explicit verification.
EVENT_PROVIDERS = {
    41: ('microsoft-windows-kernel-power', 'kernel-power'),
    6008: ('eventlog',), 1074: ('user32',),
    13: ('microsoft-windows-kernel-general',),
    6006: ('eventlog',),
    7: ('disk',), 51: ('disk',),
    55: ('ntfs', 'microsoft-windows-ntfs'),
    129: ('storahci', 'stornvme', 'iastora', 'iastorac', 'storport'),
    153: ('disk',), 7045: ('service control manager',),
    1000: ('application error',), 1002: ('application hang',),
}
EVENT_FILTERS = {'System': (41, 6008, 1074, 13, 6006, 7, 51, 55, 129, 153, 7045),
                 'Application': (1000, 1002)}
EVENT_PAGE_SIZE = 80


@dataclass(frozen=True)
class EventCollection:
    events: list[dict]
    advances: dict[str, int]
    resets: set[str]
    saturated: bool


def system_events(bookmarks: dict[str, int] | None = None) -> EventCollection | None:
    """Page from committed Windows RecordID bookmarks in ascending order.

    A missing bookmark starts with a bounded two-day history. Existing cursors
    have no two-day cutoff, allowing offline catch-up while the log retains it.
    Windows may rotate/clear logs: detect obvious RecordID resets and warn.
    """
    if os.name != 'nt':
        return None
    bookmarks = bookmarks or {}
    results: list[dict] = []
    advances: dict[str, int] = {}
    resets: set[str] = set()
    saturated = False
    log = logging.getLogger('nodo-agent')
    for channel, ids in EVENT_FILTERS.items():
        cursor = max(0, int(bookmarks.get(channel, 0)))
        ids_xpath = ' or '.join(f'EventID={event_id}' for event_id in ids)
        # Inputs are fixed channel names and integer cursors, never user strings.
        script = (f"$ErrorActionPreference='Stop'; $log='{channel}'; $cursor={cursor}; "
                  "$recent=Get-WinEvent -LogName $log -MaxEvents 1 -ErrorAction SilentlyContinue; "
                  "$newest=if($recent){[long]$recent.RecordId}else{$null}; "
                  "$old=Get-WinEvent -LogName $log -Oldest -MaxEvents 1 -ErrorAction SilentlyContinue; "
                  "$oldest=if($old){[long]$old.RecordId}else{$null}; "
                  "$reset=($null -ne $newest -and $newest -lt $cursor); "
                  "if($reset){$cursor=0}; "
                  f"$where='({ids_xpath}) and (EventRecordID > '+$cursor+')'; "
                  "if($cursor -eq 0){$where=$where+' and TimeCreated[timediff(@SystemTime) <= 172800000]'}; "
                  "$xpath='*[System['+$where+']]'; "
                  "$items=@(Get-WinEvent -LogName $log -FilterXPath $xpath -Oldest "
                  f"-MaxEvents {EVENT_PAGE_SIZE} -ErrorAction SilentlyContinue | ForEach-Object {{ "
                  "$description=try{[string]$_.Message}catch{$null}; "
                  "if($description -and $description.Length -gt 700){$description=$description.Substring(0,700)}; "
                  "$sid=if($_.UserId){[string]$_.UserId.Value}else{$null}; "
                  "[PSCustomObject]@{RecordId=$_.RecordId;EventId=$_.Id;"
                  "Provider=[string]$_.ProviderName;Time=$_.TimeCreated.ToUniversalTime().ToString('o');"
                  "Channel=$log;Level=$_.Level;Message=$description;UserSid=$sid}}); "
                  "[PSCustomObject]@{Events=$items;LatestRecordId=$newest;OldestRecordId=$oldest;Reset=$reset} "
                  "| ConvertTo-Json -Depth 5 -Compress")
        raw = _windows_query(script, timeout=20)
        if not isinstance(raw, dict) or not isinstance(raw.get('Events'), (list, dict, type(None))):
            log.warning('No se pudo consultar el registro %s; marcador conservado', channel)
            continue
        did_reset = raw.get('Reset') is True
        oldest = raw.get('OldestRecordId')
        if cursor > 0 and type(oldest) is int and oldest > cursor + 1:
            log.warning('Registro %s: posible pérdida de cobertura: marcador %d, primer registro conservado %d',
                        channel, cursor, oldest)
        if did_reset:
            resets.add(channel)
            log.warning('Registro %s reiniciado o rotacion detectada; no se garantiza cobertura previa', channel)
        items = _records(raw.get('Events'))
        if len(items) >= EVENT_PAGE_SIZE:
            saturated = True
            log.warning('Registro %s: lote de %d eventos saturado; continuara desde el marcador', channel, EVENT_PAGE_SIZE)
        previous = 0 if did_reset else cursor
        for item in items:
            record_id = item.get('RecordId')
            if type(record_id) is not int or record_id <= previous or item.get('Channel') != channel:
                continue
            # Advance even if a provider is unrecognized, otherwise the cursor stalls.
            previous = record_id
            event_id = item.get('EventId')
            provider = str(item.get('Provider') or '')[:120]
            if event_id not in ids or provider.casefold() not in EVENT_PROVIDERS.get(event_id, ()):
                log.info('Evento del registro %s no clasificado: id %s, proveedor %s', channel, event_id, provider)
                continue
            try:
                dt = datetime.fromisoformat(str(item['Time']).replace('Z', '+00:00'))
                if dt.tzinfo is None:
                    continue
                at = dt.astimezone(timezone.utc).isoformat(timespec='seconds')
            except (TypeError, ValueError, KeyError):
                continue
            message = item.get('Message')
            # Bound UTF-8 byte length: 30 events must fit the API batch limit.
            if not isinstance(message, str):
                message = None
            else:
                message = message.replace('\x00', '').strip()
                message = message.encode('utf-8', errors='replace')[:1200].decode('utf-8', errors='ignore') or None
            sid = item.get('UserSid')
            sid = sid[:120] if isinstance(sid, str) and sid.startswith('S-') else None
            results.append({'channel': channel, 'record_id': record_id,
                            'event_id': event_id, 'provider': provider,
                            'observed_at': at, 'message': message, 'user_sid': sid,
                            'level': item.get('Level') if type(item.get('Level')) is int else None})
        if previous > (0 if did_reset else cursor):
            advances[channel] = previous
    return EventCollection(results, advances, resets, saturated)


def collect_diagnostics() -> dict:
    return {
        'captured_at': datetime.now(timezone.utc).isoformat(timespec='seconds'),
        'hardware': _section(lambda: normalize.hardware(hardware())),
        'disks': _section(lambda: normalize.disks(disks())),
        'security': _section(lambda: normalize.security(security())),
    }


def valid_neighbor(ip: str, mac: str) -> bool:
    """No multicast/broadcast and no IPv4 broadcast from placeholder ARP rows."""
    try:
        address = ipaddress.ip_address(ip)
    except (TypeError, ValueError):
        return False
    if address.version != 4 or not address.is_private or address.is_multicast or address.is_unspecified or address.is_loopback or address.is_link_local:
        return False
    if str(address) == '255.255.255.255':
        return False
    parts = mac.replace('-', ':').split(':') if isinstance(mac, str) else []
    if len(parts) != 6:
        return False
    try:
        octets = [int(p, 16) for p in parts if len(p) == 2]
    except ValueError:
        return False
    return len(octets) == 6 and any(octets) and not (octets[0] & 1)

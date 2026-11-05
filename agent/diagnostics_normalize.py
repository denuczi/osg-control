"""Whitelist Windows CIM JSON into stable, size-bounded API payloads."""
from __future__ import annotations


def text(value, limit: int = 120) -> str | None:
    result = str(value).strip() if value is not None else ''
    return result[:limit] or None


def integer(value, maximum: int | None = None) -> int | None:
    # Unknown hardware sensor values (255, negative sentinels) are absent,
    # never silently changed to zero or interpreted as a real measurement.
    if type(value) not in (int, float) or value < 0 or int(value) != value:
        return None
    if maximum is not None and value > maximum:
        return None
    return int(value)


def records(value):
    return [row for row in value if isinstance(row, dict)] if isinstance(value, list) else (
        [value] if isinstance(value, dict) else [])


def hardware(raw):
    if not isinstance(raw, dict) or not (raw.get('Manufacturer') or raw.get('Model')):
        return None
    string_fields = {'Manufacturer': 160, 'Model': 160, 'BiosVersion': 160,
                     'Serial': 160, 'Board': 160, 'BatteryStatus': 20}
    result = {key: text(raw.get(key), length) for key, length in string_fields.items()}
    for key, maximum in {'MemorySlots': 128, 'BatteryPercent': 100,
                         'BatteryCycles': 10000000, 'BatteryDesignCapacity': 1000000000,
                         'BatteryFullChargeCapacity': 1000000000}.items():
        result[key] = integer(raw.get(key), maximum)
    result['BatteryPresent'] = raw.get('BatteryPresent') if type(raw.get('BatteryPresent')) is bool else None
    result['MemoryModules'] = [{
        'CapacityBytes': integer(item.get('CapacityBytes')),
        'SpeedMHz': integer(item.get('SpeedMHz'), 20000),
        'Manufacturer': text(item.get('Manufacturer'), 100),
        'PartNumber': text(item.get('PartNumber'), 120),
        'Slot': text(item.get('Slot'), 100),
    } for item in records(raw.get('MemoryModules'))[:64]]
    return result


def disks(raw):
    if not isinstance(raw, dict):
        return None
    drives = []
    for disk in records(raw.get('drives'))[:24]:
        item = {key: text(disk.get(key), length) for key, length in
                [('Name',160),('DeviceId',40),('MediaType',30),('Health',30)]}
        for key, maximum in [('Temperature',150),('Wear',100),('PowerOnHours',100000000),
                             ('ReadErrors',None),('WriteErrors',None)]:
            item[key] = integer(disk.get(key), maximum)
        drives.append(item)
    return {'drives': drives} if drives else None


def windows_version(raw):
    """A bounded OS identity and commercial license observation (not a verdict)."""
    if not isinstance(raw, dict):
        return None
    status = raw.get('EsuCommercialStatus')
    if status not in ('licensed', 'not_detected', 'unknown', 'not_applicable'):
        status = 'unknown'
    years = raw.get('EsuLicensedYears')
    if not isinstance(years, list):
        years = []
    years = sorted({item for item in years if type(item) is int and 1 <= item <= 3})
    if (status == 'licensed' and not years) or (status == 'not_detected' and years):
        status = 'unknown'
        years = []
    return {
        'Caption': text(raw.get('Caption'), 160),
        'EditionId': text(raw.get('EditionId'), 80),
        'DisplayVersion': text(raw.get('DisplayVersion'), 32),
        'Build': integer(raw.get('Build'), 999999),
        'UBR': integer(raw.get('UBR'), 999999),
        'EsuCommercialStatus': status,
        'EsuLicensedYears': years,
    }


def security(raw):
    if not isinstance(raw, dict):
        return None
    profiles = []
    for item in records(raw.get('FirewallProfiles'))[:8]:
        if isinstance(item.get('Name'), str) and type(item.get('Enabled')) is bool:
            profiles.append({'Name': text(item['Name'], 30), 'Enabled': item['Enabled']})
    providers = [text(name, 160) for name in (raw.get('AntivirusProviders') or [])[:12]
                 if isinstance(name, str)] if isinstance(raw.get('AntivirusProviders'), list) else []
    defender = text(raw.get('DefenderMode'), 60)
    windows = windows_version(raw.get('Windows'))
    realtime = raw.get('DefenderRealtime') if type(raw.get('DefenderRealtime')) is bool else None
    if defender is None and realtime is None and not profiles and not providers and raw.get('WindowsUpdatesPending') is None and windows is None:
        return None
    return {
        'DefenderMode': defender, 'DefenderRealtime': realtime,
        'DefenderSignatureAge': integer(raw.get('DefenderSignatureAge')),
        'DefenderSignatureUpdated': text(raw.get('DefenderSignatureUpdated'), 64),
        'FirewallProfiles': profiles, 'AntivirusProviders': providers,
        'WindowsUpdatesPending': integer(raw.get('WindowsUpdatesPending'), 100000),
        'ProtectionQueryStatus': raw.get('ProtectionQueryStatus')
            if raw.get('ProtectionQueryStatus') in ('ok', 'unavailable') else 'unknown',
        'Windows': windows,
    }

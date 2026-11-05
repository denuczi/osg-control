"""Passive Windows WM_DEVICECHANGE volume notifications, no network scanning.

A hidden top-level window receives USB and optical volume broadcasts. Each
notification is committed to the local SQLite outbox with a UTC timestamp.
The periodic WMI reconciliation remains as fallback: no monitoring mechanism
can guarantee an exhaustive physical-device audit without Windows Event Log.
"""
from __future__ import annotations

import ctypes
import logging
import os
import queue
import time
from ctypes import wintypes
from datetime import datetime, timezone

LOG = logging.getLogger('osg-media')
DBT_DEVICEARRIVAL = 0x8000
DBT_DEVICEREMOVECOMPLETE = 0x8004
DBT_DEVTYP_VOLUME = 0x00000002
DBTF_MEDIA = 0x0001
WM_DEVICECHANGE = 0x0219


class BroadcastVolume(ctypes.Structure):
    _fields_ = [('size', wintypes.DWORD), ('device_type', wintypes.DWORD),
                ('reserved', wintypes.DWORD), ('unitmask', wintypes.DWORD),
                ('flags', wintypes.WORD)]


def volume_letters(mask: int) -> list[str]:
    return [f'{chr(65 + i)}:' for i in range(26) if mask & (1 << i)]


def event_volumes(kind: int, address: int) -> list[tuple[str, str, str]]:
    """Extract only device-letter and event kind from trusted OS notification."""
    if kind not in (DBT_DEVICEARRIVAL, DBT_DEVICEREMOVECOMPLETE) or not address:
        return []
    header = ctypes.cast(address, ctypes.POINTER(BroadcastVolume)).contents
    if header.device_type != DBT_DEVTYP_VOLUME or header.size < ctypes.sizeof(BroadcastVolume):
        return []
    event = 'connected' if kind == DBT_DEVICEARRIVAL else 'disconnected'
    media_class = 'optical' if header.flags & DBTF_MEDIA else 'usb'
    return [(event, drive, media_class) for drive in volume_letters(header.unitmask)]


def watch_media_events(spool, stop_event, refresh_event=None) -> None:
    if os.name != 'nt':
        return
    import win32api
    import win32gui
    import win32con

    classname = f'OSGMediaObserver_{os.getpid()}'
    hinstance = win32api.GetModuleHandle(None)
    inbox: queue.Queue[tuple[str, str, str, str]] = queue.Queue(maxsize=128)

    def callback(hwnd, message, wparam, lparam):
        if message == WM_DEVICECHANGE:
            observed = datetime.now(timezone.utc).isoformat(timespec='milliseconds')
            try:
                for kind, device, media_class in event_volumes(wparam, lparam):
                    try:
                        inbox.put_nowait((kind, device, media_class, observed))
                    except queue.Full:
                        LOG.error('Cola temporal de eventos Windows llena: revisar el servicio')
            except (OSError, ValueError, ctypes.ArgumentError) as exc:
                LOG.warning('Notificación de medio inválida (%s)', type(exc).__name__)
        return win32gui.DefWindowProc(hwnd, message, wparam, lparam)

    wc = win32gui.WNDCLASS()
    wc.hInstance = hinstance
    wc.lpszClassName = classname
    wc.lpfnWndProc = callback
    atom = win32gui.RegisterClass(wc)
    hwnd = None
    try:
        # Hidden top-level window, not HWND_MESSAGE (message-only windows do not
        # receive system broadcasts). It has no taskbar or tray representation.
        hwnd = win32gui.CreateWindowEx(0, classname, 'OSG Media Observer', 0,
                                      0, 0, 0, 0, 0, 0, hinstance, None)
        while not stop_event.is_set() or not inbox.empty():
            win32gui.PumpWaitingMessages()
            for _ in range(32):
                try:
                    event, drive, media, observed = inbox.get_nowait()
                except queue.Empty:
                    break
                try:
                    recorded = spool.record_media_notification(event, drive, media, observed)
                    if recorded and event == 'connected' and refresh_event is not None:
                        refresh_event.set()
                except (OSError, ValueError) as exc:
                    LOG.error('No se persistió un evento de medio (%s)', type(exc).__name__)
            if not stop_event.is_set():
                stop_event.wait(0.2)
    finally:
        if hwnd:
            win32gui.DestroyWindow(hwnd)
        win32gui.UnregisterClass(classname, hinstance)

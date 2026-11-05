@if($device->is_demo)
    <span class="device-status {{ $device->isOnline() ? 'online' : 'offline' }}"><span class="status-pip"></span>{{ $device->isOnline() ? 'Demo · datos ficticios' : 'Demo · sin conexión' }}</span>
@elseif($device->isOnline())
    <span class="device-status online"><span class="status-pip"></span>En línea</span>
@else
    <span class="device-status offline"><span class="status-pip"></span>Sin conexión</span>
@endif

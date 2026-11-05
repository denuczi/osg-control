@php($statusLabels = ['pending' => 'Pendiente', 'dispatched' => 'Enviada', 'completed' => 'Ejecutada', 'failed' => 'Fallida', 'unknown' => 'Sin confirmar', 'expired' => 'Vencida'])
<span class="command-status status-{{ $command->status }}">{{ $statusLabels[$command->status] ?? $command->status }}</span>

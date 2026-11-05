<?php

use Illuminate\Support\Facades\Schedule;

// No depende de que el administrador tenga el panel abierto.
Schedule::command('nodo:maintain-telemetry --checkpoint')->hourly()
    ->name('nodo-maintain-telemetry')->withoutOverlapping();

Schedule::command('nodo:prune-reports')->dailyAt('03:10')
    ->name('nodo-prune-reports')->withoutOverlapping();

// Conservar auditoría; eliminar solo propuestas antiguas (no órdenes ni registros de actividad).
Schedule::call(static function (): void {
    \App\Models\AiActionProposal::query()->where('expires_at', '<', now()->subDays(7))->delete();
})->dailyAt('03:40')->name('nodo-prune-ai-proposals')->withoutOverlapping();

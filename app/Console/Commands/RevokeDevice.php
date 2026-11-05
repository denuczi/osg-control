<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;

class RevokeDevice extends Command
{
    protected $signature = 'nodo:revoke {uuid}';
    protected $description = 'Revocar las credenciales de un equipo';

    public function handle(): int
    {
        $device = Device::query()->where('uuid', $this->argument('uuid'))->first();
        if (!$device) { $this->error('Equipo no encontrado.'); return self::FAILURE; }
        if (!$this->confirm("¿Revocar acceso a {$device->name}?", false)) { return self::FAILURE; }
        $device->update(['revoked_at' => now()]);
        $this->info('Credenciales revocadas.');
        return self::SUCCESS;
    }
}

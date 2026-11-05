<?php

namespace App\Console\Commands;

use App\Models\EnrollmentCode;
use Illuminate\Console\Command;

class GenerateEnrollmentCode extends Command
{
    protected $signature = 'nodo:enroll-code {--minutes=15 : Minutos antes de que expire}';
    protected $description = 'Emitir código de enrolamiento de un solo uso para una computadora';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        if ($minutes < 1 || $minutes > 120) {
            $this->error('La vigencia debe estar entre 1 y 120 minutos.');
            return self::FAILURE;
        }
        $code = bin2hex(random_bytes(24));
        EnrollmentCode::query()->create(['code_hash' => hash('sha256', $code), 'expires_at' => now()->addMinutes($minutes)]);
        $this->components->info("Código (único uso, {$minutes} min): {$code}");
        $this->warn('Compartilo solo con el operador del equipo. No se volverá a mostrar.');
        return self::SUCCESS;
    }
}

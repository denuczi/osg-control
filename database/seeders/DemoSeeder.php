<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\MetricSample;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Datos exclusivamente ficticios para probar las vistas Blade en APP_ENV=local. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('local')) {
            throw new \RuntimeException('DemoSeeder solo se permite con APP_ENV=local.');
        }

        $fixtures = [
            ['uuid' => '00000000-0000-4000-8000-000000000101', 'name' => 'PC-Administración', 'hostname' => 'DEMO-ADMIN', 'platform' => 'Windows 11', 'cpu' => 24.3, 'ram' => 48.2, 'disk' => 63.5, 'memory' => 8, 'active' => true, 'processes' => [
                ['pid' => 4021, 'name' => 'chrome.exe', 'memory_mb' => 615.4], ['pid' => 3112, 'name' => 'EXCEL.EXE', 'memory_mb' => 231.7], ['pid' => 2190, 'name' => 'explorer.exe', 'memory_mb' => 95.2],
            ]],
            ['uuid' => '00000000-0000-4000-8000-000000000102', 'name' => 'PC-Recepción', 'hostname' => 'DEMO-RECEP', 'platform' => 'Windows 10', 'cpu' => 76.8, 'ram' => 82.1, 'disk' => 51.4, 'memory' => 8, 'active' => true, 'processes' => [
                ['pid' => 5603, 'name' => 'chrome.exe', 'memory_mb' => 945.1], ['pid' => 4236, 'name' => 'WINWORD.EXE', 'memory_mb' => 418.8], ['pid' => 2710, 'name' => 'explorer.exe', 'memory_mb' => 84.3],
            ]],
            ['uuid' => '00000000-0000-4000-8000-000000000103', 'name' => 'PC-Laboratorio', 'hostname' => 'DEMO-LAB03', 'platform' => 'Windows 11', 'cpu' => 0, 'ram' => 0, 'disk' => 38.5, 'memory' => 4, 'active' => false, 'processes' => []],
        ];

        DB::transaction(function () use ($fixtures): void {
            foreach ($fixtures as $index => $fixture) {
                $device = Device::query()->firstOrNew(['uuid' => $fixture['uuid']]);
                if ($device->exists && !$device->is_demo) {
                    throw new \RuntimeException('El UUID del fixture ya pertenece a un equipo real.');
                }
                $device->fill([
                    'name' => $fixture['name'], 'hostname' => $fixture['hostname'], 'platform' => $fixture['platform'],
                    'token_hash' => hash('sha256', 'nodo-control-demo-invalido-'.$fixture['uuid']),
                    'is_demo' => true,
                    'last_seen_at' => $fixture['active'] ? now() : null,
                    'last_sampled_at' => $fixture['active'] ? now() : null,
                    'cpu_percent' => $fixture['active'] ? $fixture['cpu'] : null,
                    'memory_percent' => $fixture['active'] ? $fixture['ram'] : null,
                    'disk_percent' => $fixture['disk'],
                    'memory_total_bytes' => $fixture['memory'] * 1024 ** 3,
                    'processes' => array_map(fn ($p) => $p + ['started_at' => 1780000000.0 + $p['pid'], 'can_terminate' => false], $fixture['processes']),
                    'revoked_at' => null,
                ]);
                $device->save();

                // Seeder repetible: se regeneran SOLO las métricas del dispositivo de prueba.
                $device->metrics()->delete();
                if (!$fixture['active']) { continue; }
                $samples = [];
                for ($i = 72; $i >= 0; --$i) {
                    $phase = ($i + $index * 7) / 7;
                    $samples[] = [
                        'device_id' => $device->id,
                        'cpu_percent' => round(max(1, min(99, $fixture['cpu'] + sin($phase) * 13 + cos($phase * 0.4) * 5)), 2),
                        'memory_percent' => round(max(1, min(99, $fixture['ram'] + sin($phase / 2) * 3)), 2),
                        'disk_percent' => $fixture['disk'],
                        'recorded_at' => now()->subSeconds($i * 50),
                    ];
                }
                MetricSample::query()->insert($samples);
            }
        });
        $this->command?->info('Tres equipos ficticios cargados. Las acciones remotas permanecen deshabilitadas.');
    }
}

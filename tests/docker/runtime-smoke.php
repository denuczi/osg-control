<?php

// Run in the worker with:
// docker compose exec -T worker php < tests/docker/runtime-smoke.php
// All database writes use a separate SQLite database in memory.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n");
    exit(1);
});
config()->set('database.default', 'sqlite');
config()->set('database.connections.sqlite.database', ':memory:');
config()->set('database.connections.sqlite.url', null);
config()->set('cache.default', 'array');
config()->set('session.driver', 'array');
Illuminate\Support\Facades\DB::purge('sqlite');
if (Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== ':memory:') {
    throw new RuntimeException('La prueba requiere una base de datos en memoria');
}
if (Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException('Fallaron las migraciones de prueba');
}
echo "Migraciones completas en SQLite en memoria: OK\n";

$files = [];
$report = null;
try {
    $token = bin2hex(random_bytes(32));
    $device = App\Models\Device::query()->create([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'name' => 'Prueba Docker', 'hostname' => 'DOCKER-QA',
        'platform' => 'Windows 11', 'token_hash' => hash('sha256', $token),
    ]);
    $http = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $request = Illuminate\Http\Request::create('/api/v1/agents/heartbeat', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ], json_encode(['hostname' => 'DOCKER-QA', 'platform' => 'Windows 11']));
    $response = $http->handle($request);
    $http->terminate($request, $response);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('Falló heartbeat: HTTP '.$response->getStatusCode().' '.$response->getContent());
    }
    echo "API heartbeat con autenticación de agente: OK\n";

    $user = App\Models\User::query()->create([
        'name' => 'Prueba Docker', 'email' => 'docker-qa@example.test',
        'password' => bin2hex(random_bytes(32)), 'is_admin' => true,
    ]);
    $report = App\Models\DeviceReport::query()->create([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'device_id' => $device->id, 'user_id' => $user->id,
        'status' => 'pending', 'ai_requested' => false, 'ai_status' => 'disabled',
        'period_from' => now()->subDays(7), 'period_to' => now(),
    ]);
    Illuminate\Support\Facades\Queue::connection('database')->push(
        new App\Jobs\GenerateDeviceReport($report->id), '', 'reports'
    );
    Illuminate\Support\Facades\Artisan::call('queue:work', [
        'connection' => 'database', '--queue' => 'reports', '--once' => true,
        '--tries' => 1, '--timeout' => 180,
    ]);
    $report->refresh();
    if ($report->status !== 'ready') {
        throw new RuntimeException('Falló informe en cola: '.$report->status.' / '.$report->error_code);
    }
    $files[] = $report->file_path;
    echo "Cola database/reports e informe PDF de dispositivo: OK\n";

    $inventory = app(App\Services\Reports\FleetInventoryCollector::class)->collect(Carbon\CarbonImmutable::now());
    $files[] = app(App\Services\Reports\FleetPdfRenderer::class)->render(
        (string) Illuminate\Support\Str::uuid(), $inventory, (string) Illuminate\Support\Str::uuid()
    );
    foreach ($files as $file) {
        if (file_get_contents($file, false, null, 0, 5) !== '%PDF-' || filesize($file) < 500) {
            throw new RuntimeException('PDF inválido');
        }
    }
    echo "Informe PDF de inventario consolidado: OK\n";
} finally {
    if ($report?->file_path) { $files[] = $report->file_path; }
    foreach (array_unique($files) as $file) {
        if (is_file($file)) { unlink($file); }
    }
}

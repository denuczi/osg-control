<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(\App\Domain\AI\AssistantRuntime::class);
        $this->app->scoped(\App\Services\DisplayTimezone::class);
        $this->app->bind(\App\Domain\AI\Contracts\ChatModel::class,
            \App\Domain\AI\Infrastructure\ChatCompletionModel::class);
        $this->app->bind(\App\Services\Reports\Contracts\FleetRenderer::class,
            \App\Services\Reports\FleetPdfRenderer::class);
        $this->app->bind(\App\Services\Reports\Contracts\PdfRenderer::class,
            \App\Services\Reports\ReportPdfRenderer::class);
        $this->app->bind(\App\Services\Reports\Contracts\ConclusionWriter::class,
            \App\Services\Reports\ReportConclusionWriter::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(app()->environment('local'));
        // La cuota de cada equipo es independiente: varios agentes pueden
        // compartir la misma IPv4 pública o usar una LAN con NAT.
        RateLimiter::for('agent', fn (Request $request) =>
            Limit::perMinute(120)->by(hash('sha256', (string) $request->bearerToken()))
        );
        // Cada solicitud tiene una credencial independiente; veinte equipos
        // detrás del mismo NAT deben poder consultar sin bloquearse entre sí.
        RateLimiter::for('pairing-poll', fn (Request $request) => [
            Limit::perMinute(25)->by('pairing-token:'.hash('sha256', (string) $request->bearerToken())),
            Limit::perMinute(1200)->by('pairing-ip:'.(string) $request->ip()),
        ]);
    }
}

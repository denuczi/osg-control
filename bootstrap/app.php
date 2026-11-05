<?php

use App\Http\Middleware\AuthenticateAgent;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\EnsureAdministrator;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->alias([
            'admin' => EnsureAdministrator::class,
            'agent' => AuthenticateAgent::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Laravel serializa las excepciones de rutas API como JSON.
    })
    ->create();

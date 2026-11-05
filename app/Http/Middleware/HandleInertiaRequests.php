<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request),
            'appName' => (string) config('app.name', 'Nodo Control'),
            'displayTimezone' => app(\App\Services\DisplayTimezone::class)->current(),
            'auth' => ['user' => $request->user()?->only('id', 'name', 'email', 'is_admin')],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}

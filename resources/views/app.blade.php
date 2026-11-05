<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ config('app.name', 'Nodo Control') }}</title>
    <meta name="application-name" content="{{ config('app.name', 'Nodo Control') }}">
    <meta name="theme-color" content="#f7f7f7">
    @vite('resources/js/app.ts')
    <x-inertia::head />
</head>
<body>
    <x-inertia::app />
</body>
</html>

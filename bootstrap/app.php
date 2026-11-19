<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Di belakang proxy HTTPS (Docker/Caddy): percayai X-Forwarded-* agar URL, redirect, dan cookie memakai https. Isi TRUSTED_PROXIES=* di server.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') ? (env('TRUSTED_PROXIES') === '*' ? '*' : array_map('trim', explode(',', env('TRUSTED_PROXIES')))) : null);
        $middleware->alias([
            'requisition.role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'integration.token' => \App\Http\Middleware\IntegrationToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Sesi/CSRF kedaluwarsa (419) pada halaman System Requisition -> langsung ke login, bukan halaman galat.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() === 419 && ! $request->expectsJson() && $request->is('requisition*')) {
                return redirect()->route('login')->with('status', 'Sesi Anda telah berakhir, silakan login kembali.');
            }
        });
    })->create();

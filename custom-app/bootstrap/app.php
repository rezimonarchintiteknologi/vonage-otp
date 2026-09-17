<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Domain\Otp\Exceptions\OtpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*') || $request->is('api/*') || $request->expectsJson(),
        );

        // Satu bentuk error untuk seluruh API: kode mesin, pesan manusia,
        // detail opsional. Tidak ada jejak internal yang bocor ke tenant.
        $exceptions->render(function (OtpException $e, Request $request) {
            return response()->json($e->toArray(), $e->status);
        });
    })->create();

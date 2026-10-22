<?php

use App\Http\Middleware\AuditRequest;
use App\Http\Middleware\EnsurePasswordIsCurrent;
use App\Http\Middleware\EnsureRoleAbility;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->redirectGuestsTo(null);
        // Document text is layout data: leading spaces, repeated spaces and tabs must
        // survive the request so the editor preview and generated PDF remain identical.
        $middleware->trimStrings(except: [
            fn (Request $request): bool => $request->is('api/v1/quotations/*/document-editor/*'),
        ]);
        $middleware->alias([
            'ability' => EnsureRoleAbility::class,
            'audit' => AuditRequest::class,
            'password.current' => EnsurePasswordIsCurrent::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

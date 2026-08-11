<?php

namespace Tests\Unit;

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SessionMiddlewareTest extends TestCase
{
    public function test_login_and_authenticated_api_routes_always_start_a_session(): void
    {
        $webMiddleware = app('router')->getMiddlewareGroups()['web'] ?? [];

        $this->assertContains(
            StartSession::class,
            $webMiddleware,
            'The web middleware group must start a session.'
        );

        foreach (['api/v1/auth/login', 'api/v1/auth/me'] as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($route) => $route->uri() === $uri);

            $this->assertNotNull($route, "Route [{$uri}] was not registered.");
            $this->assertContains(
                'web',
                $route->middleware(),
                "Route [{$uri}] must use the web middleware group."
            );
        }
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIntegrationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.integration.token');

        abort_if($expected === '' || ! hash_equals($expected, (string) $request->bearerToken()), 401, 'Неверный токен интеграции');

        return $next($request);
    }
}

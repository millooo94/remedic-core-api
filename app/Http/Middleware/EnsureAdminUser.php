<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin() || ! $user->is_active || ! $user->hasAdminApproval()) {
            abort(Response::HTTP_FORBIDDEN, 'Accesso non autorizzato.');
        }

        return $next($request);
    }
}

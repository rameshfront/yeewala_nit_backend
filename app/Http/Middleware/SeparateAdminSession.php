<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SeparateAdminSession
{
    /**
     * Handle an incoming request.
     * Enforce separate session cookies for Admin portal ('admin_session')
     * and normal User portal ('yeewala_session') so logging out in one tab
     * does not terminate the session in the other.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isAdmin = $request->is('api/v1/admin*')
            || $request->is('admin*')
            || $request->header('X-Admin-Portal') === '1';

        if ($isAdmin) {
            config(['session.cookie' => 'admin_session']);
        } else {
            config(['session.cookie' => 'yeewala_session']);
        }

        return $next($request);
    }
}

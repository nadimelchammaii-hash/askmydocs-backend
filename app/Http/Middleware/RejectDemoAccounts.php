<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks account settings (name, email, password) for demo accounts: they are
 * temporary and shared samples, not a place to keep personal details.
 */
class RejectDemoAccounts
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isDemo()) {
            throw new ApiException(403, 'demo_account', 'Demo accounts cannot change account settings. Create your own account to keep your documents.');
        }

        return $next($request);
    }
}

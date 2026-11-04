<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defensive headers for every response of this API. The API only returns JSON and
 * PDF files to the SPA (fetched with XHR), so nothing may be rendered, framed or
 * run from its own origin.
 */
class SecurityHeaders
{
    /** @var array<string, string> */
    public const HEADERS = [
        // Never guess a different content type (e.g. treat JSON or a PDF as HTML).
        'X-Content-Type-Options' => 'nosniff',
        // Nothing of the API may be shown inside a frame (clickjacking).
        'X-Frame-Options' => 'DENY',
        // Responses load nothing and run nothing, even when opened directly.
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; sandbox",
        'Referrer-Policy' => 'no-referrer',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceHttpsAndHsts
{
    /**
     * Handle an incoming request.
     * Enforces HTTPS via 301 permanent redirect and attaches HSTS headers to prevent SSL-stripping/MITM.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if request was received over plain HTTP (respecting reverse proxies)
        $isHttps = $request->isSecure() || strtolower((string) $request->header('X-Forwarded-Proto')) === 'https';

        if (! $isHttps && app()->environment('production')) {
            $secureUrl = 'https://'.$request->getHttpHost().$request->getRequestUri();

            return redirect()->to($secureUrl, 301);
        }

        $response = $next($request);

        // Strict-Transport-Security: 1 year, all subdomains, preloaded
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}

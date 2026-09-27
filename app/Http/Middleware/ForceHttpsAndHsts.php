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
        // Check if request was received over plain HTTP (respecting reverse proxies) or on www host
        $isHttps = $request->isSecure() || strtolower((string) $request->header('X-Forwarded-Proto')) === 'https';
        $host = (string) $request->getHttpHost();
        $isWww = str_starts_with($host, 'www.');

        if ((! $isHttps || $isWww) && app()->environment('production')) {
            $canonicalHost = $isWww ? preg_replace('/^www\./i', '', $host) : $host;
            $secureUrl = 'https://'.$canonicalHost.$request->getRequestUri();

            return redirect()->to($secureUrl, 301);
        }

        $response = $next($request);

        // Strict-Transport-Security: 1 year, all subdomains, preloaded
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
        $response->headers->set('Cross-Origin-Resource-Policy', 'cross-origin');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'; base-uri 'none'; form-action 'self';");

        return $response;
    }
}
